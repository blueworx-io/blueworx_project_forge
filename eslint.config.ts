import js from "@eslint/js";
import globals from "globals";
import tseslint from "typescript-eslint";
import pluginReact from "eslint-plugin-react";
import reactHooks from "eslint-plugin-react-hooks";
import { defineConfig } from "eslint/config";

export default defineConfig([
  // plugin-update-checker is vendored third-party JS, .wp-test is a disposable
  // WordPress instance, and .foundation is the shared check scripts CI checks
  // out into the workspace — none of it is ours to lint.
  {
    ignores: [
      "assets/**",
      "node_modules/**",
      "dist/**",
      "dist-zip/**",
      "vendor/**",
      "plugin-update-checker/**",
      ".wp-test/**",
      ".foundation/**",
      "test-results/**",
      "playwright-report/**",
    ],
  },
  { files: ["**/*.{js,mjs,cjs,ts,mts,cts,jsx,tsx}"], plugins: { js }, extends: ["js/recommended"], languageOptions: { globals: globals.browser } },
  tseslint.configs.recommended,
  pluginReact.configs.flat.recommended,
  {
    settings: { react: { version: "detect" } },
  },
  {
    plugins: { "react-hooks": reactHooks },
    rules: {
      ...reactHooks.configs.recommended.rules,
      "react-hooks/exhaustive-deps": "warn",
      "react-hooks/set-state-in-effect": "warn",
    },
  },
  // Playwright specs and its config run under Node, not in the browser.
  {
    files: ["tests/**/*.js", "playwright.config.js"],
    languageOptions: { globals: { ...globals.node } },
  },
  {
    rules: {
      "react/react-in-jsx-scope": "off",
      "react/prop-types": "off",
      "@typescript-eslint/no-explicit-any": "warn",
    },
  },
]);
