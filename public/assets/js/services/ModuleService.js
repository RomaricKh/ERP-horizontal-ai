// services/ModuleService.js
import { apiFetch } from '../Auth/apiFetch.js';

let activeModulesCache = null;

/** Fetch active modules for current business from /settings/modules endpoint */
export async function fetchActiveModules() {
    const res = await apiFetch('api/settings/modules');
    const data = await res.json();
    if (data.success) {
        activeModulesCache = data.data.active_modules;
    }
    return activeModulesCache || [];
}

/** Get cached active modules (synchronous) */
export function getActiveModules() {
    return activeModulesCache || [];
}

/** Set active modules from /auth/me response */
export function setActiveModules(modules) {
    activeModulesCache = modules;
}

/** Check if a module code is active */
export function isModuleActive(moduleCode) {
    if (!moduleCode) return true; // No module association = always visible
    const modules = getActiveModules();
    return modules.includes(moduleCode);
}

/** Invalidate cache (triggers re-fetch on next access) */
export function invalidateModuleCache() {
    activeModulesCache = null;
}
