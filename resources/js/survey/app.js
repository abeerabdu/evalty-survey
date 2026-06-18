// import { createApp, h } from "vue";
// import { createInertiaApp } from "@inertiajs/vue3";

// const pages = import.meta.glob("./pages/**/*.vue");

// createInertiaApp({
//   resolve: (name) => {
//     const page = pages[`./pages/${name}.vue`];
//     console.log(Object.keys(pages));
//     if (!page) {
//       throw new Error(`Page not found: ${name}`);
//     }

//     return page();
//   },

//   setup({ el, App, props, plugin }) {
//     createApp({
//       render: () => h(App, props),
//     })
//       .use(plugin)
//       .mount(el);
//   },
// });

// // import { createApp, h } from "vue";
// // import { createInertiaApp } from "@inertiajs/vue3";

// // createInertiaApp({
// //   resolve: (name) => {
// //     return import(`./pages/${name}.vue`);
// //   },

// //   setup({ el, App, props, plugin }) {
// //     createApp({ render: () => h(App, props) })
// //       .use(plugin)
// //       .mount(el);
// //   },
// // });

import { createApp } from "vue";
import SurveyBuilder from "./pages/SurveyBuilder.vue";

createApp(SurveyBuilder).mount("#survey-app");
