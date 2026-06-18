import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

export default defineConfig({
  plugins: [vue()],
  define: {
    "process.env": {},
  },
  build: {
    outDir: "dist",
    rollupOptions: {
      output: {
        entryFileNames: "survey.js",
        assetFileNames: "survey.css",
      },
    },

    lib: {
      entry: "resources/js/survey/app.js",
      name: "SurveyBuilder",
      fileName: () => "survey.js",
    },
  },
});
