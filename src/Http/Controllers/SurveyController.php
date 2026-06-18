<?php

namespace Evalty\Survey\Http\Controllers;

use Illuminate\Routing\Controller;
use Evalty\Survey\Helpers\ApiResponse;
use Evalty\Survey\Mail\SurveyInvitationMail;
use Evalty\Survey\Models\Question;
use Evalty\Survey\Models\Survey;
use Evalty\Survey\Models\SurveyInvitation;
use Evalty\Survey\Services\Survey\AnswerSnapshotBuilder;
use Evalty\Survey\Services\Survey\SurveyBuilderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;

class SurveyController extends Controller
{

    public function __construct(
        protected SurveyBuilderService $surveyBuilder,
        protected AnswerSnapshotBuilder $answerSnapshotBuilder,
    ) {}
    // public function index()
    // {
    //     return view('survey::index');
    // }
    public function indexx()
    {

        $surveys = Survey::query()
            ->select('id', 'status', 'logo', 'created_at')
            ->latest()
            ->get();

        // ✅ Move formatting to service
        $surveys = $this->surveyBuilder->mapCollection($surveys);
        return view('survey::index');
        //  return view('survey::index', [
        //     'surveys' => $surveys,
        // ]);
        // return Inertia::render('survey/index', [
        //     'surveys' => $surveys,
        // ]);
    }
    public function index()
    {

        $surveys = Survey::query()
            ->select('id', 'status', 'logo', 'created_at')
            ->latest()
            ->get();

        return view('survey::index', [
            'surveys' => $surveys,
        ]);
        // return Inertia::render('SurveyBuilder', [
        //     'surveys' => $surveys,
        // ]);
    }

    public function getDetails(Survey $survey)
    {
        $surveyData = $this->surveyBuilder->getDetailsForAdmin($survey->id);
        return Inertia::render('tenant/survey/Details', [
            'survey' => $surveyData,
            'share_url' => route('survey.public.show', ['uuid' => $survey->uuid], absolute: true),
        ]);
    }

    public function invitations(Survey $survey)
    {
        $survey->load([
            'invitations' => fn($q) => $q->select('id', 'survey_id', 'email'),
        ]);

        return ApiResponse::success([
            'invitations' => $survey->invitations
                ->map(fn(SurveyInvitation $i): array => [
                    'id' => $i->id,
                    'email' => $i->email,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function translationTree(Survey $survey)
    {
        return ApiResponse::success(
            $this->surveyBuilder->getDetailsForTranslations($survey->id),
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'default_lang' => 'required|in:en,ar',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'template_key' => 'required|exists:survey_templates,key',
        ]);

        // ✅ Use service
        $survey = $this->surveyBuilder->createWithTranslations($validated);
        $this->surveyBuilder->applyTemplate($survey);

        $survey = $this->surveyBuilder->map($survey);

        if ($request->wantsJson()) {
            return ApiResponse::success($survey, 'Survey created successfully');
        }

        return redirect()
            ->route(tenant() ? 'tenant.survey' : 'survey')
            ->with('success', 'Survey created successfully.');
    }

    public function updateLanguages(Request $request, Survey $survey)
    {
        $validated = $request->validate([
            'default_lang' => 'required|string|max:20',
            'languages' => 'required|array|min:1',
            'languages.*' => 'required|string|max:20',
        ]);

        DB::transaction(function () use ($survey, $validated) {

            $languages = collect($validated['languages']);

            // ✅ Ensure default_lang exists in languages
            if (! $languages->contains($validated['default_lang'])) {
                $languages->prepend($validated['default_lang']);
            }

            // ✅ Update default language on survey
            $survey->update([
                'default_lang' => $validated['default_lang'],
            ]);

            // ✅ Create missing translations
            foreach ($languages as $locale) {
                $survey->translations()->firstOrCreate([
                    'locale' => $locale,
                ]);
            }

            // ❗ OPTIONAL: remove languages not selected
            $survey->translations()
                ->whereNotIn('locale', $languages)
                ->delete();
        });

        // ✅ Return clean response
        return ApiResponse::success([
            'id' => $survey->id,
            'default_lang' => $survey->default_lang,
            'languages' => $survey->translations()
                ->pluck('locale')
                ->values(),
        ], 'Languages updated successfully');
    }

    public function manage(Survey $survey)
    {
        return Inertia::render('tenant/survey/Survey', [
            'survey' => $this->surveyBuilder->getDetailsForAdmin($survey->id),
        ]);
    }

    public function review(Request $request, Survey $survey)
    {
        $locale = (string) ($request->user()?->locale ?? $survey->default_lang ?? app()->getLocale());

        return Inertia::render('tenant/survey/surveyReview', [
            'survey' => $this->surveyBuilder->buildTenantSurveyReviewPayload($survey->id, $locale),
        ]);
    }

    public function submit(Request $request, Survey $survey)
    {
        // Store survey response and answers
        $validated = $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.value' => 'nullable',
            'submitted_locale' => 'nullable|string|max:32',
        ]);

        $user = $request->user();
        $questionIds = collect($validated['answers'])->pluck('question_id')->unique()->map(fn($id) => (int) $id)->all();
        $questionsById = Question::query()
            ->with(['options.translations'])
            ->whereIn('id', $questionIds)
            ->whereHas('section', fn($q) => $q->where('survey_id', $survey->id))
            ->get()
            ->keyBy('id')
            ->all();

        if (count($questionsById) !== count($questionIds)) {
            return response()->json([
                'success' => false,
                'message' => 'One or more questions are not part of this survey.',
            ], 422);
        }

        $submittedLocale = $validated['submitted_locale'] ?? $survey->default_lang ?? 'en';

        $response = DB::transaction(function () use ($survey, $validated, $request, $user, $submittedLocale, $questionsById) {
            $response = $survey->responses()->create([
                'user_id' => $user ? $user->id : null,
                'ip_address' => $request->ip(),
                'submitted_at' => now(),
                'submitted_locale' => $submittedLocale,
                // 'tenant_id' => tenant('id'),
            ]);

            $this->answerSnapshotBuilder->persistSubmissionAnswers(
                $response,
                $validated['answers'],
                $questionsById,
                $submittedLocale
            );

            return $response->load('answers');
        });

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'response' => $response,
            ]);
        }

        return redirect()->route(tenant() ? 'tenant.survey.details' : 'survey')
            ->with('success', 'Survey submitted successfully.');
    }

    public function update(Request $request, Survey $survey)
    {
        $validated = $request->validate([
            'default_lang' => 'required|in:en,ar',
            'title' => 'required',

            'description' => 'nullable',
        ]);

        $survey = $this->surveyBuilder->updateWithTranslations($survey, $validated);
        $survey = $this->surveyBuilder->map($survey);

        if ($request->wantsJson()) {
            return ApiResponse::success($survey, 'Survey updated successfully');
        }

        return redirect()
            ->route(tenant() ? 'tenant.survey' : 'survey')
            ->with('success', 'Survey updated successfully.');
    }

    public function updateTranslation(Request $request, Survey $survey)
    {
        $validated = $request->validate([
            'locale' => 'required|string',
            'title' => 'sometimes|string',
            'description' => 'sometimes|string',
        ]);

        $data = $this->surveyBuilder->updateTranslation($survey, $validated);

        return ApiResponse::success($data, 'Translation updated successfully');
    }

    public function updateImage(Request $request, Survey $survey)
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'max:2048'],
        ]);

        if ($survey->logo && Storage::disk('public')->exists($survey->logo)) {
            Storage::disk('public')->delete($survey->logo);
        }

        $path = $request->file('image')->store('surveys/logos', 'public');

        $survey->update([
            'logo' => $path,
        ]);

        if ($request->wantsJson()) {
            // Refresh the Survey model instance to get the latest data from the database (including the updated 'logo' field)
            $survey = $survey->fresh();
            $data = $survey->only(['id', 'title', 'description', 'status', 'logo', 'created_at']);
            $data['logo_url'] = $survey->logo
                ? (tenant()
                    ? tenant_asset($survey->logo)
                    : asset('storage/' . $survey->logo))
                : null;

            return ApiResponse::success($data, 'Survey image updated successfully');
        }

        $surveyRoute = tenant() ? 'tenant.survey.manage' : 'survey.manage';

        return redirect()
            ->route($surveyRoute, $survey)
            ->with('success', 'Survey image updated successfully.');
    }

    public function destroy(Request $request, Survey $survey)
    {
        $survey->delete();
        if ($request->wantsJson()) {
            return ApiResponse::success(
                null,
                'Survey deleted successfully'
            );
        }
        $surveyRoute = tenant() ? 'tenant.survey' : 'survey';

        return redirect()->route($surveyRoute)->with('success', 'Survey deleted successfully.');
    }

    public function publish(Request $request, Survey $survey)
    {
        $request->validate([
            'access_type' => 'required|in:public,private',
            'open_at' => 'nullable|date',
            'close_at' => 'nullable|date|after:open_at',
            'emails' => 'nullable|array',
            'emails.*' => 'email',
            'domain' => 'nullable|string|max:1024',
            'response_limit' => 'nullable|integer',
        ]);

        $domainCsv = Survey::normaliseDomainCsv($request->input('domain'));

        try {
            DB::transaction(function () use ($request, $survey, $domainCsv) {
                $survey->update([
                    'status' => 'published',
                    'published_at' => now(),
                    'access_type' => $request->access_type,
                    'open_at' => $request->open_at,
                    'close_at' => $request->close_at,
                    'domain' => $domainCsv,
                    'response_limit' => $request->response_limit,
                ]);

                if ($request->access_type === 'public') {
                    $survey->invitations()->delete();
                }

                if ($request->access_type === 'private') {
                    $this->syncInvitations($survey, $request->emails ?? []);
                }

                $survey->ensureShortSlug();
            });
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'short_slug')) {
                return response()->json([
                    'success' => false,
                    'message' => __('survey.share.shortSlugUnavailable'),
                    'code' => 'short_slug_unavailable',
                ], 422);
            }

            throw $e;
        }

        $survey->refresh()->load('invitations');

        $payload = array_merge($survey->toArray(), [
            'invitations' => $survey->invitations,
            'share_url' => route('survey.public.show', ['uuid' => $survey->uuid], absolute: true),
            'short_url' => $survey->short_slug
                ? route('survey.short', ['code' => $survey->short_slug], absolute: true)
                : null,
            'allowed_domains' => $survey->allowed_domains,
        ]);

        if ($request->wantsJson()) {
            return ApiResponse::success($payload, 'Survey published successfully');
        }

        return response()->json([
            'message' => 'Survey published successfully',
            'data' => $payload,
        ]);
    }

    public function ensureShortLink(Survey $survey)
    {
        $survey->refresh();

        if ($survey->short_slug) {
            return ApiResponse::success(
                [
                    'id' => $survey->id,
                    'uuid' => $survey->uuid,
                    'short_slug' => $survey->short_slug,
                    'status' => $survey->status,
                    'short_url' => route('survey.short', ['code' => $survey->short_slug], absolute: true),
                ],
                __('survey.share.shortLinkReady')
            );
        }

        try {
            DB::transaction(function () use ($survey) {
                $survey->ensureShortSlug();
            });
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'short_slug')) {
                return response()->json([
                    'success' => false,
                    'message' => __('survey.share.shortSlugUnavailable'),
                    'code' => 'short_slug_unavailable',
                ], 422);
            }

            throw $e;
        }

        $survey->refresh();

        $payload = [
            'id' => $survey->id,
            'uuid' => $survey->uuid,
            'short_slug' => $survey->short_slug,
            'status' => $survey->status,
            'short_url' => $survey->short_slug
                ? route('survey.short', ['code' => $survey->short_slug], absolute: true)
                : null,
        ];

        return ApiResponse::success($payload, __('survey.share.shortLinkReady'));
    }

    public function close(Request $request, Survey $survey)
    {

        $survey->update([
            'status' => $request->status,
        ]);

        if ($request->wantsJson()) {
            return ApiResponse::success(
                $survey->fresh()->only('status'),
                'Survey closed successfully'
            );
        }
    }

    public function redraft(Request $request, Survey $survey)
    {

        $survey->update([
            'status' => 'draft',
        ]);

        if ($request->wantsJson()) {

            return ApiResponse::success($survey->fresh()->only('status'));
        }
    }

    public function peopleWithAccess(Survey $survey)
    {
        $people = collect();

        // 1️⃣ Owner
        if ($survey->owner) {
            $people->push([
                'type' => 'owner',
                'name' => $survey->owner->name,
                'email' => $survey->owner->email,
            ]);
        }

        // 2️⃣ Invited by email or link
        foreach ($survey->invitations as $invite) {
            $people->push([
                'type' => 'invited',
                'email' => $invite->email,
                'token' => $invite->token,
                'accepted' => $invite->accepted,
                'responded' => $invite->has_responded,
                'expires_at' => $invite->expires_at,
                'status' => match (true) {
                    $invite->has_responded => 'responded',
                    $invite->accepted => 'accepted',
                    $invite->isExpired() => 'expired',
                    default => 'pending'
                },
            ]);
        }

        return response()->json(
            $people->unique('email')->values()
        );
    }

    public function getDefaults(Request $request, Survey $survey)
    {
        $emails = $survey->invitations()->pluck('email')->toArray();
        $defaults = config('survey.messages');
        $data = [
            'survey' => $survey,
            'default_messages' => $defaults,
            'emails' => $emails,
        ];

        if ($request->wantsJson()) {
            return ApiResponse::success($data);
        }

        return response()->json($data);
    }

    public function getSurveyMessages(Request $request, Survey $survey)
    {
        $surveyMessages = $this->surveyBuilder->getSurveyMessages($survey->id);

        // default messages from config
        $defaultMessages = config('survey.messages');

        $data = [
            'survey_messages' => $surveyMessages, // ✅ full structure (sections, questions, options, translations)
            'default_messages' => $defaultMessages, // ✅ raw defaults
        ];

        if ($request->wantsJson()) {
            return ApiResponse::success($data);
        }

        return response()->json($data);
    }

    public function updateSettings(Request $request, Survey $survey)
    {
        $data = $request->validate([
            'access_type' => 'required|in:public,private',
            'response_limit' => 'nullable|integer',
            'open_at' => 'nullable|date',
            'close_at' => 'nullable|date',
            'emails' => 'nullable|array',
            'emails.*' => 'email',
            'domain' => 'nullable|string|max:1024',
            'welcome_message' => 'nullable|array',
            'welcome_message.en' => 'nullable|string',
            'welcome_message.ar' => 'nullable|string',

            'completed_message' => 'nullable|array',
            'completed_message.en' => 'nullable|string',
            'completed_message.ar' => 'nullable|string',

            'closed_message' => 'nullable|array',
            'closed_message.en' => 'nullable|string',
            'closed_message.ar' => 'nullable|string',

            'limit_message' => 'nullable|array',
            'limit_message.en' => 'nullable|string',
            'limit_message.ar' => 'nullable|string',
        ]);

        $emails = $data['emails'] ?? [];
        unset($data['emails']);

        if (array_key_exists('domain', $data)) {
            $data['domain'] = Survey::normaliseDomainCsv($data['domain'] ?? null);
        }

        $survey->update($data);

        if ($survey->access_type === 'public') {
            $survey->invitations()->delete();
        }

        if ($survey->access_type === 'private') {
            $this->syncInvitations($survey, $emails);
        }

        return response()->json([
            'message' => 'Settings saved successfully',
        ]);
    }

    public function duplicate(Survey $survey)
    {
        $newSurvey = $survey->duplicate();

        // return redirect()->route(
        //     tenant() ? 'tenant.survey.details' : 'survey',
        //     ['survey' => $newSurvey->id]
        // );
        return ApiResponse::success($newSurvey, 'Survey duplicate successfully');
    }

    public function updateMessages(Request $request, Survey $survey)
    {
        $validated = $request->validate([
            'locale' => 'required|string',
            'messages' => 'required|array',
        ]);

        $data = $this->surveyBuilder->updateSurveyMessages($survey, $validated);

        return ApiResponse::success($data, 'Messages saved successfully');
    }

    private function syncInvitations(Survey $survey, array $emails)
    {
        $existingEmails = $survey->invitations()->pluck('email')->toArray();

        $emailsToAdd = array_diff($emails, $existingEmails);
        $emailsToRemove = array_diff($existingEmails, $emails);

        // delete removed emails
        SurveyInvitation::where('survey_id', $survey->id)
            ->whereIn('email', $emailsToRemove)
            ->delete();

        // add new emails
        foreach ($emailsToAdd as $email) {

            $token = Str::random(32);

            SurveyInvitation::create([
                'survey_id' => $survey->id,
                'email' => $email,
                'token' => $token,
                'expires_at' => $survey->close_at
                    ? $survey->close_at
                    : now()->addDays(30),
            ]);

            $link = tenant_route(
                tenant()->domains->first()->domain,
                'survey.invite.show',
                [$survey->uuid]
            ) . '?token=' . $token;

            Mail::to($email)->send(
                new SurveyInvitationMail($survey, $link)
            );
        }
    }

    public function saveBuilder(
        Request $request,
        Survey $survey,
        SurveyBuilderService $service
    ) {

        $data = $request->validate([
            'builder_items' => ['required', 'array'],
            'builder_items.*.type' => ['required', 'string', 'in:section,direct_question'],

            'builder_items.*.id' => ['nullable'],
            'builder_items.*.order' => ['nullable', 'integer'],
            'builder_items.*.translations' => ['array'],

            'builder_items.*.questions' => ['array'],
            'builder_items.*.questions.*.id' => ['nullable'],
            'builder_items.*.questions.*.type' => ['required', 'string'],
            'builder_items.*.questions.*.required' => ['boolean'],
            'builder_items.*.questions.*.translations' => ['array'],
            'builder_items.*.questions.*.options' => ['array'],
            'builder_items.*.questions.*.options.*.id' => ['nullable'],
            'builder_items.*.questions.*.options.*.value' => ['nullable', 'string'],
            'builder_items.*.questions.*.options.*.translations' => ['array'],

            'builder_items.*.question' => ['nullable', 'array'],
            'builder_items.*.question.id' => ['nullable'],
            'builder_items.*.question.type' => ['required_with:builder_items.*.question', 'string'],
            'builder_items.*.question.required' => ['boolean'],
            'builder_items.*.question.translations' => ['array'],
            'builder_items.*.question.options' => ['array'],
            'builder_items.*.question.options.*.id' => ['nullable'],
            'builder_items.*.question.options.*.value' => ['nullable', 'string'],
            'builder_items.*.question.options.*.translations' => ['array'],
        ]);

        $payload = $service->saveBuilder($survey, $data);

        return response()->json([
            'data' => $payload,
        ]);
    }
}
