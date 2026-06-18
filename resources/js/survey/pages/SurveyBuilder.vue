<template>
    <div class="p-6">
        <h1 class="text-xl font-bold">Survey Builder</h1>

        <p>Vue is working 🎉</p>

        <ul>
            <li v-for="survey in surveys" :key="survey.id">
                {{ survey.id }}
            </li>
        </ul>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'

const el = document.getElementById('survey-app')

const surveys = ref(JSON.parse(el.dataset.surveys || '[]'))
</script>
