# POS Desktop

برنامج نقاط بيع لسطح مكتب Windows، بواجهة عربية، ومخزون وعملاء وموردين وتقارير وطباعة إيصالات.

[تحميل الإصدار الأول](https://github.com/ABDO-TECK/pos-desktop/releases) · [بناء GitHub](https://github.com/ABDO-TECK/pos-desktop/actions)

نسخة Windows x64 تشمل PHP وMariaDB وJava وQZ Tray؛ لا تحتاج XAMPP. إصدار 0.0.1 تجريبي ومثبته غير موقع بشهادة Windows Authenticode. راجع وصف الإصدار وبصمات SHA-256 قبل التثبيت.

## بناء المشروع من السورس على Windows

المتطلبات: Node.js 22، وPHP 8.2 مع Composer، و7-Zip الكامل في PATH. اعتمادات npm وComposer مثبتة بإصدارات ملفات lock؛ محركات التشغيل تُنزّل من مصادرها الرسمية مع التحقق من SHA-256.

```powershell
npm ci
npm --prefix frontend ci
composer install --working-dir=backend --no-dev --prefer-dist --no-interaction
npm --prefix frontend run typecheck
npm run frontend:build
npm run build:phar
php scripts/verify-phar-runtime.php
npm run prepare:desktop-runtime
npm run prepare:print-runtime
npm run verify:desktop-runtime
npm run electron:build -- --x64
node scripts/verify-installer-package.mjs
```

ملف التثبيت: `dist-electron/POS-Desktop-Setup-0.0.1.exe`.
بعد تجهيز محركات التشغيل وبناء الواجهة، يمكن التشغيل محليا بالأمر `npx electron .`.

Workflow `Windows Setup and first release` يبني ويفحص الحزمة على GitHub، ثم يشغلها على runner نظيف للتحقق من API وقاعدة البيانات وخدمة الطباعة. دفع tag بصيغة `vX.Y.Z` ينشر الإصدار بعد نجاح الفحوص. التشغيل اليدوي يبني artifact فقط.

## البيانات والأمان

السورس لا يتضمن قواعد بيانات تشغيل أو كلمات مرور أو مفاتيح خاصة. تظهر بيانات المدير الأولية على شاشة أول تشغيل ويجب تغيير كلمة المرور. بيانات الأعمال خارج مجلد التثبيت، داخل `%PROGRAMDATA%\POS System` أو مسار المستخدم البديل عند تعذر الكتابة؛ إعدادات الجهاز داخل `%APPDATA%\POS System`. أنشئ نسخة احتياطية من الإعدادات قبل ترقية تثبيت قائم.

التجارب المحلية نُفذت على قاعدة معزولة وأكدت بقاء بيانات التشغيل الحالية دون تغيير. فُحص البيع النقدي والآجل والحجز وعكس العمليات، والنسخ الاحتياطي والاستعادة، وإيصالات PDF بمقاسي A4 و80mm. الطباعة الورقية تتطلب طابعة وتعريفا مناسبا، ولم تُختبر على جهاز فعلي في هذا الفحص.
