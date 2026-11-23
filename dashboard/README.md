# لوحة تحكم الأدمن المستقلة (React + Firebase) — Nexus Agency

هذا المجلد يحتوي على **لوحة تحكم الأدمن كاملة** جاهزة للرفع والتشغيل مباشرة على Vercel أو أي استضافة (cPanel / Apache / Hostinger)، ومربوطة مباشرة بمشروع Firebase (`moorrcv`).

---

## التجهيز والتشغيل فوراً

### 1) الرفع المباشر (مجلد `dashboard/`)
يحتوي المجلد على:
- `index.html` + ملفات `assets/`
- `vercel.json` (لتشغيل الروابط على Vercel بدون خطأ 404)
- `.htaccess` (لتشغيل الروابط على استضافات cPanel / Apache)
- `admin-api.php` (بديل احتياطي على PHP عند اللزوم)

**تفعيل قاعدة بيانات Firebase (`moorrcv`):**
1. افتح لوحة Firebase Console لمشروع `moorrcv` ← **Build ← Firestore Database ← Create database** (وضع Start in test mode أو قواعد القراءة/الكتابة المناسبة).
2. بعد التفعيل مباشرة، أي حساب تنشئه أو تعدّله من الداش بورد يظهر ويشتغل لايف على الموقع الرئيسي فوراً ومن أي جهاز أو موبايل بدون أي ربط يدوي.

**الخطوات لـ GitHub + Vercel أو cPanel:**
- ارفع محتويات مجلد `dashboard/` مباشرة على Vercel أو إلى مجلد الاستضافة (`public_html`) وافتح الرابط في المتصفح.

---

### 2) البناء من الكود المصدري (Local / Git)
```bash
npm install
npm run build
```
الناتج سيخرج في مجلد `dist/`.
