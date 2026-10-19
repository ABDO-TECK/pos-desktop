# QZ Tray — إدارة النسخ

## النسخة الحالية
- **qz-tray.js**: v2.2.x (تحقق من أول سطر في qz-tray.js)
- **آخر تحديث**: 2026-05-06

## كيفية التحديث
1. حمّل أحدث نسخة من: https://github.com/qzind/tray/releases
2. انسخ `qz-tray.js` إلى `frontend/public/qz/qz-tray.js`
3. انسخ `qz-tray.jar` إلى `tray/out/dist/qz-tray.jar`
4. شغّل `setup-qz-runtime.ps1` لتحديث الـ portable
5. اختبر الطباعة من شاشة POS

## ملاحظات
- هذا الملف يُحمّل كـ `<script>` وليس npm package (مطلوب لأنه يعتمد على window.qz)
- لا تحذف qz-config.js — يحتوي إعدادات الاتصال بالسيرفر
