# POS Desktop

برنامج نقاط بيع لسطح مكتب Windows، بواجهة عربية، ومخزون وعملاء وموردين وتقارير وطباعة إيصالات.

[تحميل الإصدار الأول](https://github.com/ABDO-TECK/pos-desktop/releases) · [بناء GitHub](https://github.com/ABDO-TECK/pos-desktop/actions)

نسخة Windows x64 تشمل PHP وMariaDB وJava وQZ Tray؛ لا تحتاج XAMPP. Setup 0.0.1 يحمل أحدث إصلاحات التطبيق (المراجعة الداخلية 0.0.8)، ومثبته غير موقع بشهادة Windows Authenticode. راجع وصف الإصدار وبصمات SHA-256 قبل التثبيت.

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

Workflow `Windows Setup release` يبني ويفحص الحزمة على GitHub. التشغيل اليدوي يبني artifact؛ خيار publish ينشر Setup بعد نجاح الفحوص. تحديثات delta تُنشر من ريبو التحديثات فقط.

## البيانات والأمان

السورس لا يتضمن قواعد بيانات تشغيل أو كلمات مرور أو مفاتيح خاصة. تظهر بيانات المدير الأولية على شاشة أول تشغيل ويجب تغيير كلمة المرور. بيانات الأعمال خارج مجلد التثبيت، داخل `%PROGRAMDATA%\POS System` أو مسار المستخدم البديل عند تعذر الكتابة؛ إعدادات الجهاز داخل `%APPDATA%\POS System`. أنشئ نسخة احتياطية من الإعدادات قبل ترقية تثبيت قائم.

التجارب المحلية نُفذت على قاعدة معزولة وأكدت بقاء بيانات التشغيل الحالية دون تغيير. فُحص البيع النقدي والآجل والحجز وعكس العمليات، والنسخ الاحتياطي والاستعادة، وإيصالات PDF بمقاسي A4 و80mm. الطباعة الورقية تتطلب طابعة وتعريفا مناسبا، ولم تُختبر على جهاز فعلي في هذا الفحص.

## Update releases

Full Setup releases live here. Signed stable and beta deltas live in [pos-desktop-updates](https://github.com/ABDO-TECK/pos-desktop-updates). See [publishing and delta verification](docs/release.md). Setup 0.0.1 carries application revision 0.0.8; update revisions continue increasing independently. Older installations need the new complete Setup once to acquire the new desktop shell and update endpoint; existing data is outside the installation directory. Delta changes deployed artifacts only; business data and device credentials are excluded.
