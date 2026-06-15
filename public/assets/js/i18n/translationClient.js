/**
 * TranslationClient Module
 * Singleton ES module that manages translations, settings loading, and text direction.
 * Fetches regional settings and translations from the API, configures the Formatter,
 * and provides translation lookup and DOM application functions.
 */

import { apiFetch } from '../Auth/apiFetch.js';
import { configure, formatNumber, formatDate, formatCurrency, formatPhone } from './formatter.js';

// Default settings used when the settings API fails
const DEFAULT_SETTINGS = {
  number_format: '1,234.56',
  date_format: 'Y-m-d',
  currency_format: 'USD',
  phone_format: '+1 (###) ###-####'
};

// Module-level state
let dictionary = {};
let currentLang = 'en';
let listenerRegistered = false;

/**
 * Initialize the translation client.
 * Fetches settings from the API, configures the Formatter,
 * fetches translations for the active language, and applies text direction.
 */
export async function init() {
  let settings = DEFAULT_SETTINGS;

  // Fetch regional settings
  try {
    const res = await apiFetch('/api/settings');
    const json = await res.json();
    if (res.ok && json.success && json.data) {
      settings = json.data;
    }
  } catch (e) {
    // Use defaults on error
  }

  // Configure the formatter with the loaded (or default) settings
  configure(settings);

  // Determine language
  currentLang = settings.language || 'en';

  // Fetch translations for the active language
  try {
    const res = await apiFetch(`/api/translations/${currentLang}`);
    const json = await res.json();
    if (res.ok && json.success && json.data) {
      dictionary = json.data;
    } else {
      dictionary = {};
    }
  } catch (e) {
    dictionary = {};
  }

  // Apply text direction
  const direction = (settings.direction || 'LTR').toUpperCase();
  const htmlEl = document.documentElement;
  const bodyEl = document.body;

  if (direction === 'RTL') {
    htmlEl.setAttribute('dir', 'rtl');
    bodyEl.classList.add('rtl');
  } else {
    htmlEl.setAttribute('dir', 'ltr');
    bodyEl.classList.remove('rtl');
  }

  // Register the settings:changed listener once
  if (!listenerRegistered) {
    window.addEventListener('settings:changed', async () => {
      await init();
      applyTranslations(document.body);
    });
    listenerRegistered = true;
  }
}

/**
 * Look up a translation by key.
 * Returns the translated value if the key exists in the dictionary,
 * otherwise returns the key itself.
 * @param {string} key - The translation key
 * @returns {string} The translated value or the key
 */
export function t(key) {
  if (dictionary.hasOwnProperty(key)) {
    return dictionary[key];
  }
  return key;
}

/**
 * Apply translations to all translatable elements within a container.
 * - Elements with `data-i18n` attribute: sets textContent to the translated value
 * - Elements with `data-i18n-placeholder` attribute: sets placeholder to the translated value
 * Elements whose keys are not found in the dictionary are left unchanged.
 * @param {HTMLElement} container - The DOM container to apply translations to
 */
export function applyTranslations(container) {
  if (!container) return;

  // Apply textContent translations
  const textElements = container.querySelectorAll('[data-i18n]');
  textElements.forEach(el => {
    const key = el.getAttribute('data-i18n');
    if (dictionary.hasOwnProperty(key)) {
      el.textContent = dictionary[key];
    }
  });

  // Apply placeholder translations
  const placeholderElements = container.querySelectorAll('[data-i18n-placeholder]');
  placeholderElements.forEach(el => {
    const key = el.getAttribute('data-i18n-placeholder');
    if (dictionary.hasOwnProperty(key)) {
      el.setAttribute('placeholder', dictionary[key]);
    }
  });
}

/**
 * Expose formatter functions for use by page modules.
 * @returns {{ formatNumber: Function, formatDate: Function, formatCurrency: Function, formatPhone: Function }}
 */
export function getFormatter() {
  return {
    formatNumber,
    formatDate,
    formatCurrency,
    formatPhone
  };
}
