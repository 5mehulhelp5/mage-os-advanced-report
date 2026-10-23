/**
 * ESLint configuration for MageOS_AdvancedReport.
 *
 * Reuses the Magento coding standard flat config shipped by magento/magento-coding-standard
 * (installed by composer into vendor/) and limits it to the module JavaScript.
 */
import { defineConfig } from "eslint/config";
import magento from "./vendor/magento/magento-coding-standard/eslint/eslint.config.mjs";

export default defineConfig([
    {
        ignores: ["vendor/**", "node_modules/**"]
    },
    {
        files: ["view/**/*.js"],
        extends: [magento]
    }
]);
