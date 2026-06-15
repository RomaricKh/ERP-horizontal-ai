-- Seed tbl_translations with Arabic (ar) and French (fr) translations.
-- Uses INSERT IGNORE to avoid duplicate-key errors on re-run.

-- ============================================================
-- ARABIC (ar) TRANSLATIONS
-- ============================================================

-- Navigation
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'nav.dashboard', 'لوحة التحكم'),
('ar', 'nav.operations', 'العمليات'),
('ar', 'nav.purchase_orders', 'أوامر الشراء'),
('ar', 'nav.production_orders', 'أوامر الإنتاج'),
('ar', 'nav.invoices', 'الفواتير'),
('ar', 'nav.expenses', 'المصروفات'),
('ar', 'nav.salaries', 'الرواتب'),
('ar', 'nav.finance', 'المالية'),
('ar', 'nav.accounts', 'الحسابات'),
('ar', 'nav.exchange_rates', 'أسعار الصرف'),
('ar', 'nav.payments', 'المدفوعات'),
('ar', 'nav.collections', 'التحصيلات'),
('ar', 'nav.core_data', 'البيانات الأساسية'),
('ar', 'nav.people', 'الأشخاص'),
('ar', 'nav.items', 'الأصناف'),
('ar', 'nav.administration', 'الإدارة'),
('ar', 'nav.businesses', 'الشركات'),
('ar', 'nav.users', 'المستخدمون'),
('ar', 'nav.roles', 'الأدوار'),
('ar', 'nav.profile', 'الملف الشخصي'),
('ar', 'nav.settings', 'الإعدادات'),
('ar', 'nav.activity_log', 'سجل النشاط');

-- Common
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'common.save', 'حفظ'),
('ar', 'common.cancel', 'إلغاء'),
('ar', 'common.close', 'إغلاق'),
('ar', 'common.delete', 'حذف'),
('ar', 'common.edit', 'تعديل'),
('ar', 'common.add', 'إضافة'),
('ar', 'common.create', 'إنشاء'),
('ar', 'common.actions', 'إجراءات'),
('ar', 'common.name', 'الاسم'),
('ar', 'common.email', 'البريد الإلكتروني'),
('ar', 'common.phone', 'الهاتف'),
('ar', 'common.date', 'التاريخ'),
('ar', 'common.type', 'النوع'),
('ar', 'common.status', 'الحالة'),
('ar', 'common.amount', 'المبلغ'),
('ar', 'common.amount_fc', 'المبلغ (ع.أ)'),
('ar', 'common.amount_bc', 'المبلغ (ع.م)'),
('ar', 'common.currency', 'العملة'),
('ar', 'common.exchange_rate', 'سعر الصرف'),
('ar', 'common.balance', 'الرصيد'),
('ar', 'common.notes', 'ملاحظات'),
('ar', 'common.description', 'الوصف'),
('ar', 'common.loading', 'جارٍ التحميل…'),
('ar', 'common.account', 'الحساب'),
('ar', 'common.payee', 'المستفيد'),
('ar', 'common.customer', 'العميل'),
('ar', 'common.supplier', 'المورّد'),
('ar', 'common.category', 'الفئة'),
('ar', 'common.item', 'الصنف'),
('ar', 'common.qty', 'الكمية'),
('ar', 'common.sku', 'رمز المنتج'),
('ar', 'common.method', 'الطريقة'),
('ar', 'common.reference', 'المرجع'),
('ar', 'common.document', 'المستند'),
('ar', 'common.select', '— اختر —'),
('ar', 'common.select_currency', 'اختر عملة'),
('ar', 'common.id', 'المعرّف'),
('ar', 'common.optional_notes', 'ملاحظات اختيارية'),
('ar', 'common.auto_calculated', 'يُحسب تلقائياً'),
('ar', 'common.blank_page', 'صفحة فارغة');

-- Dashboard
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'dashboard.title', 'لوحة التحكم'),
('ar', 'dashboard.revenue', 'الإيرادات (هذا الشهر)'),
('ar', 'dashboard.expenses', 'المصروفات (هذا الشهر)'),
('ar', 'dashboard.net_profit', 'صافي الربح (هذا الشهر)'),
('ar', 'dashboard.account_balances', 'أرصدة الحسابات'),
('ar', 'dashboard.low_stock_alerts', 'تنبيهات المخزون المنخفض'),
('ar', 'dashboard.recent_transactions', 'المعاملات الأخيرة');

-- Expenses
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'expenses.title', 'المصروفات'),
('ar', 'expenses.heading', 'المصروفات'),
('ar', 'expenses.categories_btn', 'الفئات'),
('ar', 'expenses.add_btn', 'إضافة مصروف'),
('ar', 'expenses.form_title', 'تفاصيل المصروف'),
('ar', 'expenses.expense_date', 'تاريخ المصروف'),
('ar', 'expenses.amount_fc_label', 'المبلغ (ع.أ)'),
('ar', 'expenses.categories_title', 'فئات المصروفات'),
('ar', 'expenses.new_category_placeholder', 'اسم الفئة الجديدة'),
('ar', 'expenses.save_btn', 'حفظ المصروف');

-- Invoices
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'invoices.title', 'فواتير المبيعات'),
('ar', 'invoices.heading', 'فواتير المبيعات'),
('ar', 'invoices.add_btn', 'فاتورة جديدة'),
('ar', 'invoices.form_title', 'فاتورة مبيعات جديدة'),
('ar', 'invoices.details_heading', 'تفاصيل الفاتورة'),
('ar', 'invoices.select_customer', '— اختر العميل —'),
('ar', 'invoices.invoice_number', 'رقم الفاتورة'),
('ar', 'invoices.invoice_number_placeholder', 'مثال: INV-2025-001'),
('ar', 'invoices.invoice_date', 'تاريخ الفاتورة'),
('ar', 'invoices.line_items', 'بنود الفاتورة'),
('ar', 'invoices.add_line', 'إضافة بند'),
('ar', 'invoices.unit_price_fc', 'سعر الوحدة (ع.أ)'),
('ar', 'invoices.unit_price_bc', 'سعر الوحدة (ع.م)'),
('ar', 'invoices.total_fc', 'الإجمالي (ع.أ)'),
('ar', 'invoices.grand_total_fc', 'المجموع الكلي (ع.أ)'),
('ar', 'invoices.grand_total_bc', 'المجموع الكلي (ع.م)'),
('ar', 'invoices.save_btn', 'حفظ الفاتورة');

-- Purchase Orders
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'po.title', 'أوامر الشراء'),
('ar', 'po.heading', 'أوامر الشراء'),
('ar', 'po.add_btn', 'أمر شراء جديد'),
('ar', 'po.form_title', 'أمر شراء'),
('ar', 'po.details_heading', 'تفاصيل أمر الشراء'),
('ar', 'po.select_supplier', '— اختر المورّد —'),
('ar', 'po.po_number', 'رقم أمر الشراء'),
('ar', 'po.po_number_placeholder', 'مثال: PO-2025-001'),
('ar', 'po.po_date', 'تاريخ أمر الشراء'),
('ar', 'po.line_items', 'بنود الطلب'),
('ar', 'po.add_line', 'إضافة بند'),
('ar', 'po.unit_price_fc', 'سعر الوحدة (ع.أ)'),
('ar', 'po.unit_price_bc', 'سعر الوحدة (ع.م)'),
('ar', 'po.total_fc', 'الإجمالي (ع.أ)'),
('ar', 'po.grand_total_fc', 'المجموع الكلي (ع.أ)'),
('ar', 'po.grand_total_bc', 'المجموع الكلي (ع.م)'),
('ar', 'po.save_btn', 'حفظ أمر الشراء');

-- Payments
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'payments.title', 'المدفوعات'),
('ar', 'payments.heading', 'المدفوعات'),
('ar', 'payments.add_btn', 'دفعة جديدة'),
('ar', 'payments.form_title', 'تفاصيل الدفعة'),
('ar', 'payments.select_type', '— اختر النوع —'),
('ar', 'payments.select_reference', '— اختر المرجع —'),
('ar', 'payments.select_payee', '— اختر المستفيد —'),
('ar', 'payments.select_account', '— اختر الحساب —'),
('ar', 'payments.payment_info', 'معلومات الدفع'),
('ar', 'payments.payment_number', 'رقم الدفعة'),
('ar', 'payments.number_placeholder', 'PAY-2024-001'),
('ar', 'payments.save_btn', 'حفظ الدفعة');

-- Collections
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'collections.title', 'التحصيلات'),
('ar', 'collections.heading', 'التحصيلات'),
('ar', 'collections.add_btn', 'تحصيل جديد'),
('ar', 'collections.form_title', 'تفاصيل التحصيل'),
('ar', 'collections.select_type', '— اختر النوع —'),
('ar', 'collections.select_reference', '— اختر المرجع —'),
('ar', 'collections.select_payee', '— اختر المستفيد —'),
('ar', 'collections.select_account', '— اختر الحساب —'),
('ar', 'collections.slip_info', 'معلومات الإيصال'),
('ar', 'collections.slip_number_short', 'رقم الإيصال'),
('ar', 'collections.slip_placeholder', 'COL-2024-001'),
('ar', 'collections.save_btn', 'حفظ التحصيل');

-- Accounts
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'accounts.title', 'الحسابات'),
('ar', 'accounts.heading', 'الحسابات'),
('ar', 'accounts.add_btn', 'إضافة حساب'),
('ar', 'accounts.form_title', 'تفاصيل الحساب'),
('ar', 'accounts.name_placeholder', 'اسم الحساب'),
('ar', 'accounts.total_bc', 'الإجمالي (ع.م)'),
('ar', 'accounts.save_btn', 'حفظ الحساب');

-- Exchange Rates
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'exchange_rates.title', 'أسعار الصرف'),
('ar', 'exchange_rates.heading', 'أسعار الصرف'),
('ar', 'exchange_rates.add_btn', 'إضافة سعر'),
('ar', 'exchange_rates.form_title', 'سعر الصرف'),
('ar', 'exchange_rates.rate_date', 'تاريخ السعر'),
('ar', 'exchange_rates.rate_value', 'السعر'),
('ar', 'exchange_rates.source', 'المصدر'),
('ar', 'exchange_rates.source_placeholder', 'مصدر اختياري'),
('ar', 'exchange_rates.save_btn', 'حفظ السعر');

-- Salaries
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'salaries.title', 'الرواتب'),
('ar', 'salaries.heading', 'الرواتب'),
('ar', 'salaries.add_btn', 'راتب جديد'),
('ar', 'salaries.form_title', 'تفاصيل الراتب'),
('ar', 'salaries.employee', 'الموظف'),
('ar', 'salaries.select_employee', '— اختر الموظف —'),
('ar', 'salaries.period_start', 'بداية الفترة'),
('ar', 'salaries.period_end', 'نهاية الفترة'),
('ar', 'salaries.gross_amount_fc', 'المبلغ الإجمالي (ع.أ)'),
('ar', 'salaries.tax_fc_label', 'الضريبة (ع.أ)'),
('ar', 'salaries.tax_placeholder', '0.00 (اختياري)'),
('ar', 'salaries.net_amount_fc', 'صافي المبلغ (ع.أ)'),
('ar', 'salaries.save_btn', 'حفظ الراتب');

-- People
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'people.title', 'الأشخاص'),
('ar', 'people.heading', 'الأشخاص'),
('ar', 'people.add_btn', 'إضافة شخص'),
('ar', 'people.manage_types_btn', 'إدارة الأنواع'),
('ar', 'people.form_title', 'تفاصيل الشخص'),
('ar', 'people.identity_heading', 'الهوية'),
('ar', 'people.full_name', 'الاسم الكامل'),
('ar', 'people.full_name_placeholder', 'أدخل الاسم الكامل'),
('ar', 'people.phone_placeholder', 'مثال: +961 71 000 000'),
('ar', 'people.email_placeholder', 'email@example.com'),
('ar', 'people.address', 'العنوان'),
('ar', 'people.address_placeholder', 'أدخل العنوان'),
('ar', 'people.tax_number', 'الرقم الضريبي'),
('ar', 'people.tax_placeholder', 'رقم ضريبي / ضريبة القيمة المضافة'),
('ar', 'people.iban', 'رقم الآيبان'),
('ar', 'people.iban_placeholder', 'رقم الآيبان البنكي'),
('ar', 'people.business_role', 'الدور التجاري'),
('ar', 'people.payee_types_label', 'أنواع المستفيدين'),
('ar', 'people.notes_placeholder', 'ملاحظات داخلية حول جهة الاتصال'),
('ar', 'people.manage_types_title', 'إدارة أنواع المستفيدين'),
('ar', 'people.new_type_placeholder', 'اسم نوع جديد (مثال: عميل، مورّد، موظف…)'),
('ar', 'people.save_btn', 'حفظ الشخص');

-- Items
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'items.title', 'الأصناف'),
('ar', 'items.heading', 'الأصناف'),
('ar', 'items.add_btn', 'إضافة صنف'),
('ar', 'items.manage_types_btn', 'إدارة الأنواع'),
('ar', 'items.form_title', 'تفاصيل الصنف'),
('ar', 'items.item_name', 'اسم الصنف'),
('ar', 'items.item_name_placeholder', 'أدخل اسم الصنف'),
('ar', 'items.sku_placeholder', 'مثال: SKU-001'),
('ar', 'items.unit_placeholder', 'مثال: كغ، قطعة، صندوق'),
('ar', 'items.cost_price', 'سعر التكلفة'),
('ar', 'items.sale_price', 'سعر البيع'),
('ar', 'items.min_stock', 'الحد الأدنى للمخزون'),
('ar', 'items.save_btn', 'حفظ الصنف'),
('ar', 'items.types_title', 'أنواع الأصناف'),
('ar', 'items.new_type_placeholder', 'اسم نوع جديد'),
('ar', 'items.stock_view_title', 'عرض المخزون'),
('ar', 'items.stock_adjust_title', 'تعديل المخزون'),
('ar', 'items.recipe_title', 'تفاصيل الوصفة');

-- Production
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'production.title', 'أوامر الإنتاج'),
('ar', 'production.heading', 'أوامر الإنتاج'),
('ar', 'production.add_btn', 'أمر إنتاج جديد'),
('ar', 'production.form_title', 'أمر إنتاج'),
('ar', 'production.select_recipe', '— اختر الوصفة —'),
('ar', 'production.production_qty', 'كمية الإنتاج'),
('ar', 'production.components', 'المكونات'),
('ar', 'production.total_cost', 'التكلفة الإجمالية'),
('ar', 'production.unit_cost', 'تكلفة الوحدة'),
('ar', 'production.save_btn', 'بدء الإنتاج');

-- Businesses
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'businesses.title', 'الشركات'),
('ar', 'businesses.heading', 'الشركات'),
('ar', 'businesses.add_btn', 'إضافة شركة'),
('ar', 'businesses.form_title', 'تفاصيل الشركة'),
('ar', 'businesses.business_name', 'اسم الشركة'),
('ar', 'businesses.name_placeholder', 'أدخل اسم الشركة'),
('ar', 'businesses.legal_name', 'الاسم القانوني'),
('ar', 'businesses.legal_name_placeholder', 'أدخل الاسم القانوني'),
('ar', 'businesses.registration_no', 'رقم التسجيل'),
('ar', 'businesses.registration_placeholder', 'أدخل رقم التسجيل'),
('ar', 'businesses.phone_placeholder', 'أدخل رقم الهاتف'),
('ar', 'businesses.email_placeholder', 'أدخل البريد الإلكتروني'),
('ar', 'businesses.save_btn', 'حفظ الشركة');

-- Users
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'users.title', 'المستخدمون'),
('ar', 'users.heading', 'المستخدمون'),
('ar', 'users.add_btn', 'إضافة مستخدم'),
('ar', 'users.form_title', 'تفاصيل المستخدم'),
('ar', 'users.email_placeholder', 'أدخل البريد الإلكتروني'),
('ar', 'users.username_placeholder', 'أدخل اسم المستخدم'),
('ar', 'users.first_name', 'الاسم الأول'),
('ar', 'users.first_name_placeholder', 'أدخل الاسم الأول'),
('ar', 'users.last_name', 'اسم العائلة'),
('ar', 'users.last_name_placeholder', 'أدخل اسم العائلة'),
('ar', 'users.role', 'الدور'),
('ar', 'users.select_role', '— اختر الدور —'),
('ar', 'users.password', 'كلمة المرور'),
('ar', 'users.password_placeholder', 'أدخل كلمة المرور'),
('ar', 'users.confirm_password', 'تأكيد كلمة المرور'),
('ar', 'users.confirm_password_placeholder', 'أكّد كلمة المرور'),
('ar', 'users.save_btn', 'حفظ المستخدم');

-- Roles
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'roles.title', 'الأدوار'),
('ar', 'roles.heading', 'الأدوار'),
('ar', 'roles.add_btn', 'إضافة دور'),
('ar', 'roles.form_title', 'تفاصيل الدور'),
('ar', 'roles.role_name', 'اسم الدور'),
('ar', 'roles.name_placeholder', 'أدخل اسم الدور'),
('ar', 'roles.description_placeholder', 'وصف اختياري لهذا الدور'),
('ar', 'roles.permissions', 'الصلاحيات'),
('ar', 'roles.search_permissions', 'البحث في الصلاحيات…'),
('ar', 'roles.save_btn', 'حفظ الدور'),
('ar', 'roles.view_title', 'تفاصيل الدور');

-- Settings
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'settings.title', 'الإعدادات'),
('ar', 'settings.heading', 'الإعدادات الإقليمية'),
('ar', 'settings.language', 'اللغة'),
('ar', 'settings.timezone', 'المنطقة الزمنية'),
('ar', 'settings.date_format', 'تنسيق التاريخ'),
('ar', 'settings.number_format', 'تنسيق الأرقام'),
('ar', 'settings.currency_format', 'تنسيق العملة'),
('ar', 'settings.phone_format', 'تنسيق الهاتف'),
('ar', 'settings.save_btn', 'حفظ الإعدادات');

-- Auth
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'auth.login_title', 'تسجيل الدخول'),
('ar', 'auth.email_label', 'البريد الإلكتروني'),
('ar', 'auth.email_placeholder', 'أدخل البريد الإلكتروني...'),
('ar', 'auth.password_label', 'كلمة المرور'),
('ar', 'auth.password_placeholder', 'كلمة المرور'),
('ar', 'auth.login_btn', 'تسجيل الدخول'),
('ar', 'auth.forgot_password', 'نسيت كلمة المرور؟'),
('ar', 'auth.forgot_title', 'نسيت كلمة المرور؟'),
('ar', 'auth.forgot_message', 'أدخل بريدك الإلكتروني وسنرسل لك رابطاً لإعادة تعيين كلمة المرور.'),
('ar', 'auth.forgot_email_placeholder', 'أدخل البريد الإلكتروني...'),
('ar', 'auth.reset_btn', 'إعادة تعيين كلمة المرور'),
('ar', 'auth.back_to_login', 'لديك حساب بالفعل؟ سجّل الدخول!'),
('ar', 'auth.logout_title', 'هل أنت مستعد للمغادرة؟'),
('ar', 'auth.logout_message', 'اختر "تسجيل الخروج" أدناه إذا كنت مستعداً لإنهاء جلستك الحالية.'),
('ar', 'auth.logout_btn', 'تسجيل الخروج'),
('ar', 'auth.reauth_title', 'لست مستعداً للمغادرة؟'),
('ar', 'auth.stay_logged_in', 'البقاء متصلاً');

-- Activity Log
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('ar', 'activity_log.title', 'سجل النشاط'),
('ar', 'activity_log.heading', 'سجل النشاط');


-- ============================================================
-- FRENCH (fr) TRANSLATIONS
-- ============================================================

-- Navigation
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'nav.dashboard', 'Tableau de bord'),
('fr', 'nav.operations', 'Opérations'),
('fr', 'nav.purchase_orders', 'Bons de commande'),
('fr', 'nav.production_orders', 'Ordres de production'),
('fr', 'nav.invoices', 'Factures'),
('fr', 'nav.expenses', 'Dépenses'),
('fr', 'nav.salaries', 'Salaires'),
('fr', 'nav.finance', 'Finances'),
('fr', 'nav.accounts', 'Comptes'),
('fr', 'nav.exchange_rates', 'Taux de change'),
('fr', 'nav.payments', 'Paiements'),
('fr', 'nav.collections', 'Encaissements'),
('fr', 'nav.core_data', 'Données de base'),
('fr', 'nav.people', 'Personnes'),
('fr', 'nav.items', 'Articles'),
('fr', 'nav.administration', 'Administration'),
('fr', 'nav.businesses', 'Entreprises'),
('fr', 'nav.users', 'Utilisateurs'),
('fr', 'nav.roles', 'Rôles'),
('fr', 'nav.profile', 'Profil'),
('fr', 'nav.settings', 'Paramètres'),
('fr', 'nav.activity_log', 'Journal d''activité');

-- Common
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'common.save', 'Enregistrer'),
('fr', 'common.cancel', 'Annuler'),
('fr', 'common.close', 'Fermer'),
('fr', 'common.delete', 'Supprimer'),
('fr', 'common.edit', 'Modifier'),
('fr', 'common.add', 'Ajouter'),
('fr', 'common.create', 'Créer'),
('fr', 'common.actions', 'Actions'),
('fr', 'common.name', 'Nom'),
('fr', 'common.email', 'E-mail'),
('fr', 'common.phone', 'Téléphone'),
('fr', 'common.date', 'Date'),
('fr', 'common.type', 'Type'),
('fr', 'common.status', 'Statut'),
('fr', 'common.amount', 'Montant'),
('fr', 'common.amount_fc', 'Montant (DE)'),
('fr', 'common.amount_bc', 'Montant (DC)'),
('fr', 'common.currency', 'Devise'),
('fr', 'common.exchange_rate', 'Taux de change'),
('fr', 'common.balance', 'Solde'),
('fr', 'common.notes', 'Notes'),
('fr', 'common.description', 'Description'),
('fr', 'common.loading', 'Chargement…'),
('fr', 'common.account', 'Compte'),
('fr', 'common.payee', 'Bénéficiaire'),
('fr', 'common.customer', 'Client'),
('fr', 'common.supplier', 'Fournisseur'),
('fr', 'common.category', 'Catégorie'),
('fr', 'common.item', 'Article'),
('fr', 'common.qty', 'Qté'),
('fr', 'common.sku', 'Réf.'),
('fr', 'common.method', 'Méthode'),
('fr', 'common.reference', 'Référence'),
('fr', 'common.document', 'Document'),
('fr', 'common.select', '— Sélectionner —'),
('fr', 'common.select_currency', 'Sélectionner une devise'),
('fr', 'common.id', 'ID'),
('fr', 'common.optional_notes', 'Notes optionnelles'),
('fr', 'common.auto_calculated', 'Calculé automatiquement'),
('fr', 'common.blank_page', 'Page vierge');

-- Dashboard
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'dashboard.title', 'Tableau de bord'),
('fr', 'dashboard.revenue', 'Revenus (ce mois)'),
('fr', 'dashboard.expenses', 'Dépenses (ce mois)'),
('fr', 'dashboard.net_profit', 'Bénéfice net (ce mois)'),
('fr', 'dashboard.account_balances', 'Soldes des comptes'),
('fr', 'dashboard.low_stock_alerts', 'Alertes de stock bas'),
('fr', 'dashboard.recent_transactions', 'Transactions récentes');

-- Expenses
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'expenses.title', 'Dépenses'),
('fr', 'expenses.heading', 'Dépenses'),
('fr', 'expenses.categories_btn', 'Catégories'),
('fr', 'expenses.add_btn', 'Ajouter une dépense'),
('fr', 'expenses.form_title', 'Détails de la dépense'),
('fr', 'expenses.expense_date', 'Date de la dépense'),
('fr', 'expenses.amount_fc_label', 'Montant (DE)'),
('fr', 'expenses.categories_title', 'Catégories de dépenses'),
('fr', 'expenses.new_category_placeholder', 'Nom de la nouvelle catégorie'),
('fr', 'expenses.save_btn', 'Enregistrer la dépense');

-- Invoices
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'invoices.title', 'Factures de vente'),
('fr', 'invoices.heading', 'Factures de vente'),
('fr', 'invoices.add_btn', 'Nouvelle facture'),
('fr', 'invoices.form_title', 'Nouvelle facture de vente'),
('fr', 'invoices.details_heading', 'Détails de la facture'),
('fr', 'invoices.select_customer', '— Sélectionner un client —'),
('fr', 'invoices.invoice_number', 'Numéro de facture'),
('fr', 'invoices.invoice_number_placeholder', 'ex. INV-2025-001'),
('fr', 'invoices.invoice_date', 'Date de facture'),
('fr', 'invoices.line_items', 'Lignes de facturation'),
('fr', 'invoices.add_line', 'Ajouter une ligne'),
('fr', 'invoices.unit_price_fc', 'Prix unitaire (DE)'),
('fr', 'invoices.unit_price_bc', 'Prix unitaire (DC)'),
('fr', 'invoices.total_fc', 'Total (DE)'),
('fr', 'invoices.grand_total_fc', 'Total général (DE)'),
('fr', 'invoices.grand_total_bc', 'Total général (DC)'),
('fr', 'invoices.save_btn', 'Enregistrer la facture');

-- Purchase Orders
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'po.title', 'Bons de commande'),
('fr', 'po.heading', 'Bons de commande'),
('fr', 'po.add_btn', 'Nouveau BC'),
('fr', 'po.form_title', 'Bon de commande'),
('fr', 'po.details_heading', 'Détails du BC'),
('fr', 'po.select_supplier', '— Sélectionner un fournisseur —'),
('fr', 'po.po_number', 'Numéro de BC'),
('fr', 'po.po_number_placeholder', 'ex. PO-2025-001'),
('fr', 'po.po_date', 'Date du BC'),
('fr', 'po.line_items', 'Lignes de commande'),
('fr', 'po.add_line', 'Ajouter une ligne'),
('fr', 'po.unit_price_fc', 'Prix unitaire (DE)'),
('fr', 'po.unit_price_bc', 'Prix unitaire (DC)'),
('fr', 'po.total_fc', 'Total (DE)'),
('fr', 'po.grand_total_fc', 'Total général (DE)'),
('fr', 'po.grand_total_bc', 'Total général (DC)'),
('fr', 'po.save_btn', 'Enregistrer le BC');

-- Payments
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'payments.title', 'Paiements'),
('fr', 'payments.heading', 'Paiements'),
('fr', 'payments.add_btn', 'Nouveau paiement'),
('fr', 'payments.form_title', 'Détails du paiement'),
('fr', 'payments.select_type', '— Sélectionner le type —'),
('fr', 'payments.select_reference', '— Sélectionner la référence —'),
('fr', 'payments.select_payee', '— Sélectionner le bénéficiaire —'),
('fr', 'payments.select_account', '— Sélectionner le compte —'),
('fr', 'payments.payment_info', 'Informations de paiement'),
('fr', 'payments.payment_number', 'N° de paiement'),
('fr', 'payments.number_placeholder', 'PAY-2024-001'),
('fr', 'payments.save_btn', 'Enregistrer le paiement');

-- Collections
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'collections.title', 'Encaissements'),
('fr', 'collections.heading', 'Encaissements'),
('fr', 'collections.add_btn', 'Nouvel encaissement'),
('fr', 'collections.form_title', 'Détails de l''encaissement'),
('fr', 'collections.select_type', '— Sélectionner le type —'),
('fr', 'collections.select_reference', '— Sélectionner la référence —'),
('fr', 'collections.select_payee', '— Sélectionner le bénéficiaire —'),
('fr', 'collections.select_account', '— Sélectionner le compte —'),
('fr', 'collections.slip_info', 'Informations du reçu'),
('fr', 'collections.slip_number_short', 'N° de reçu'),
('fr', 'collections.slip_placeholder', 'COL-2024-001'),
('fr', 'collections.save_btn', 'Enregistrer l''encaissement');

-- Accounts
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'accounts.title', 'Comptes'),
('fr', 'accounts.heading', 'Comptes'),
('fr', 'accounts.add_btn', 'Ajouter un compte'),
('fr', 'accounts.form_title', 'Détails du compte'),
('fr', 'accounts.name_placeholder', 'Nom du compte'),
('fr', 'accounts.total_bc', 'Total (DC)'),
('fr', 'accounts.save_btn', 'Enregistrer le compte');

-- Exchange Rates
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'exchange_rates.title', 'Taux de change'),
('fr', 'exchange_rates.heading', 'Taux de change'),
('fr', 'exchange_rates.add_btn', 'Ajouter un taux'),
('fr', 'exchange_rates.form_title', 'Taux de change'),
('fr', 'exchange_rates.rate_date', 'Date du taux'),
('fr', 'exchange_rates.rate_value', 'Taux'),
('fr', 'exchange_rates.source', 'Source'),
('fr', 'exchange_rates.source_placeholder', 'Source optionnelle'),
('fr', 'exchange_rates.save_btn', 'Enregistrer le taux');

-- Salaries
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'salaries.title', 'Salaires'),
('fr', 'salaries.heading', 'Salaires'),
('fr', 'salaries.add_btn', 'Nouveau salaire'),
('fr', 'salaries.form_title', 'Détails du salaire'),
('fr', 'salaries.employee', 'Employé'),
('fr', 'salaries.select_employee', '— Sélectionner un employé —'),
('fr', 'salaries.period_start', 'Début de période'),
('fr', 'salaries.period_end', 'Fin de période'),
('fr', 'salaries.gross_amount_fc', 'Montant brut (DE)'),
('fr', 'salaries.tax_fc_label', 'Impôt (DE)'),
('fr', 'salaries.tax_placeholder', '0.00 (optionnel)'),
('fr', 'salaries.net_amount_fc', 'Montant net (DE)'),
('fr', 'salaries.save_btn', 'Enregistrer le salaire');

-- People
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'people.title', 'Personnes'),
('fr', 'people.heading', 'Personnes'),
('fr', 'people.add_btn', 'Ajouter une personne'),
('fr', 'people.manage_types_btn', 'Gérer les types'),
('fr', 'people.form_title', 'Détails de la personne'),
('fr', 'people.identity_heading', 'Identité'),
('fr', 'people.full_name', 'Nom complet'),
('fr', 'people.full_name_placeholder', 'Entrer le nom complet'),
('fr', 'people.phone_placeholder', 'ex. +961 71 000 000'),
('fr', 'people.email_placeholder', 'email@example.com'),
('fr', 'people.address', 'Adresse'),
('fr', 'people.address_placeholder', 'Entrer l''adresse'),
('fr', 'people.tax_number', 'Numéro fiscal'),
('fr', 'people.tax_placeholder', 'Numéro fiscal / TVA'),
('fr', 'people.iban', 'IBAN'),
('fr', 'people.iban_placeholder', 'IBAN bancaire'),
('fr', 'people.business_role', 'Rôle commercial'),
('fr', 'people.payee_types_label', 'Types de bénéficiaires'),
('fr', 'people.notes_placeholder', 'Notes internes sur ce contact'),
('fr', 'people.manage_types_title', 'Gérer les types de bénéficiaires'),
('fr', 'people.new_type_placeholder', 'Nom du nouveau type (ex. Client, Fournisseur, Employé…)'),
('fr', 'people.save_btn', 'Enregistrer la personne');

-- Items
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'items.title', 'Articles'),
('fr', 'items.heading', 'Articles'),
('fr', 'items.add_btn', 'Ajouter un article'),
('fr', 'items.manage_types_btn', 'Gérer les types'),
('fr', 'items.form_title', 'Détails de l''article'),
('fr', 'items.item_name', 'Nom de l''article'),
('fr', 'items.item_name_placeholder', 'Entrer le nom de l''article'),
('fr', 'items.sku_placeholder', 'ex. SKU-001'),
('fr', 'items.unit_placeholder', 'ex. kg, pcs, boîte'),
('fr', 'items.cost_price', 'Prix de revient'),
('fr', 'items.sale_price', 'Prix de vente'),
('fr', 'items.min_stock', 'Stock minimum'),
('fr', 'items.save_btn', 'Enregistrer l''article'),
('fr', 'items.types_title', 'Types d''articles'),
('fr', 'items.new_type_placeholder', 'Nom du nouveau type'),
('fr', 'items.stock_view_title', 'Vue du stock'),
('fr', 'items.stock_adjust_title', 'Ajustement de stock'),
('fr', 'items.recipe_title', 'Détails de la recette');

-- Production
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'production.title', 'Ordres de production'),
('fr', 'production.heading', 'Ordres de production'),
('fr', 'production.add_btn', 'Nouvel ordre de production'),
('fr', 'production.form_title', 'Ordre de production'),
('fr', 'production.select_recipe', '— Sélectionner une recette —'),
('fr', 'production.production_qty', 'Quantité de production'),
('fr', 'production.components', 'Composants'),
('fr', 'production.total_cost', 'Coût total'),
('fr', 'production.unit_cost', 'Coût unitaire'),
('fr', 'production.save_btn', 'Lancer la production');

-- Businesses
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'businesses.title', 'Entreprises'),
('fr', 'businesses.heading', 'Entreprises'),
('fr', 'businesses.add_btn', 'Ajouter une entreprise'),
('fr', 'businesses.form_title', 'Détails de l''entreprise'),
('fr', 'businesses.business_name', 'Nom de l''entreprise'),
('fr', 'businesses.name_placeholder', 'Entrer le nom de l''entreprise'),
('fr', 'businesses.legal_name', 'Raison sociale'),
('fr', 'businesses.legal_name_placeholder', 'Entrer la raison sociale'),
('fr', 'businesses.registration_no', 'N° d''immatriculation'),
('fr', 'businesses.registration_placeholder', 'Entrer le numéro d''immatriculation'),
('fr', 'businesses.phone_placeholder', 'Entrer le numéro de téléphone'),
('fr', 'businesses.email_placeholder', 'Entrer l''adresse e-mail'),
('fr', 'businesses.save_btn', 'Enregistrer l''entreprise');

-- Users
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'users.title', 'Utilisateurs'),
('fr', 'users.heading', 'Utilisateurs'),
('fr', 'users.add_btn', 'Ajouter un utilisateur'),
('fr', 'users.form_title', 'Détails de l''utilisateur'),
('fr', 'users.email_placeholder', 'Entrer l''adresse e-mail'),
('fr', 'users.username_placeholder', 'Entrer le nom d''utilisateur'),
('fr', 'users.first_name', 'Prénom'),
('fr', 'users.first_name_placeholder', 'Entrer le prénom'),
('fr', 'users.last_name', 'Nom de famille'),
('fr', 'users.last_name_placeholder', 'Entrer le nom de famille'),
('fr', 'users.role', 'Rôle'),
('fr', 'users.select_role', '— Sélectionner un rôle —'),
('fr', 'users.password', 'Mot de passe'),
('fr', 'users.password_placeholder', 'Entrer le mot de passe'),
('fr', 'users.confirm_password', 'Confirmer le mot de passe'),
('fr', 'users.confirm_password_placeholder', 'Confirmer le mot de passe'),
('fr', 'users.save_btn', 'Enregistrer l''utilisateur');

-- Roles
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'roles.title', 'Rôles'),
('fr', 'roles.heading', 'Rôles'),
('fr', 'roles.add_btn', 'Ajouter un rôle'),
('fr', 'roles.form_title', 'Détails du rôle'),
('fr', 'roles.role_name', 'Nom du rôle'),
('fr', 'roles.name_placeholder', 'Entrer le nom du rôle'),
('fr', 'roles.description_placeholder', 'Description optionnelle pour ce rôle'),
('fr', 'roles.permissions', 'Permissions'),
('fr', 'roles.search_permissions', 'Rechercher des permissions…'),
('fr', 'roles.save_btn', 'Enregistrer le rôle'),
('fr', 'roles.view_title', 'Détails du rôle');

-- Settings
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'settings.title', 'Paramètres'),
('fr', 'settings.heading', 'Paramètres régionaux'),
('fr', 'settings.language', 'Langue'),
('fr', 'settings.timezone', 'Fuseau horaire'),
('fr', 'settings.date_format', 'Format de date'),
('fr', 'settings.number_format', 'Format des nombres'),
('fr', 'settings.currency_format', 'Format de devise'),
('fr', 'settings.phone_format', 'Format de téléphone'),
('fr', 'settings.save_btn', 'Enregistrer les paramètres');

-- Auth
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'auth.login_title', 'Connexion'),
('fr', 'auth.email_label', 'Adresse e-mail'),
('fr', 'auth.email_placeholder', 'Entrer l''adresse e-mail...'),
('fr', 'auth.password_label', 'Mot de passe'),
('fr', 'auth.password_placeholder', 'Mot de passe'),
('fr', 'auth.login_btn', 'Se connecter'),
('fr', 'auth.forgot_password', 'Mot de passe oublié ?'),
('fr', 'auth.forgot_title', 'Mot de passe oublié ?'),
('fr', 'auth.forgot_message', 'Entrez votre adresse e-mail et nous vous enverrons un lien pour réinitialiser votre mot de passe.'),
('fr', 'auth.forgot_email_placeholder', 'Entrer l''adresse e-mail...'),
('fr', 'auth.reset_btn', 'Réinitialiser le mot de passe'),
('fr', 'auth.back_to_login', 'Vous avez déjà un compte ? Connectez-vous !'),
('fr', 'auth.logout_title', 'Prêt à partir ?'),
('fr', 'auth.logout_message', 'Sélectionnez « Déconnexion » ci-dessous si vous êtes prêt à terminer votre session.'),
('fr', 'auth.logout_btn', 'Déconnexion'),
('fr', 'auth.reauth_title', 'Pas prêt à partir ?'),
('fr', 'auth.stay_logged_in', 'Rester connecté');

-- Activity Log
INSERT IGNORE INTO tbl_translations (lang, `key`, value) VALUES
('fr', 'activity_log.title', 'Journal d''activité'),
('fr', 'activity_log.heading', 'Journal d''activité');
