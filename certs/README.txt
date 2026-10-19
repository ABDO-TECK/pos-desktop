مجلد شهادات التطوير (لا ترفع مفاتيحك إلى Git — *.pem مُستبعدة في .gitignore)

1) ثبّت mkcert: https://github.com/FiloSottile/mkcert
2) على جهاز التطوير:
   mkcert -install
3) من هذا المجلد (أو انسخ الملفات هنا بعد إنشائها):
   mkcert localhost 127.0.0.1 ::1 192.168.YOUR.IP

   سيُنشئ ملفين مثل:
   192.168.1.22+3.pem
   192.168.1.22+3-key.pem

4) ضع الملفين داخل مجلد certs/ بجانب مجلد frontend/

5) شغّل: npm run dev (من frontend/)
   إذا وُجد الزوج *-key.pem + المطابق *.pem → Vite يعمل على https://0.0.0.0:5173

6) على الهاتف: ثبّت شهادة جذر mkcert (انظر docs/local-https-lan.md)
