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
  //
  // design/ is the imported design kit: an export we read from, not code we
  // ship. It is already excluded from both plugin zips and from the workflows'
  // content checks, so linting it only ever produced findings nobody could act
  // on without editing somebody else's export.
  {
    ignores: [
      "assets/**",
      "node_modules/**",
      "dist/**",
      "dist-zip/**",
      "vendor/**",
      "plugin-update-checker/**",
      ".wp-test/**",
      // The client half of the two-instance harness, and the artifact staged
      // for it. Both are disposable WordPress trees — thousands of files the
      // linter would walk on every run.
      ".wp-test-client/**",
      ".wp-test-client-plugin/**",
      ".foundation/**",
      "test-results/**",
      "playwright-report/**",
      "design/**",
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
  // Playwright specs, its config, and the repo's own check scripts all run
  // under Node, not in the browser.
  {
    files: ["tests/**/*.{js,mjs}", "playwright*.config.js", "bin/**/*.mjs"],
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
