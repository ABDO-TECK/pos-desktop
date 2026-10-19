<?php
/**
 * أداة CLI لإدارة وثائق OpenAPI
 *
 * الاستخدام:
 *   php bin/api-docs.php validate      — التحقق من صحة ملف openapi.yaml
 *   php bin/api-docs.php stats         — عرض إحصائيات التوثيق
 *   php bin/api-docs.php routes        — عرض جميع المسارات الموثقة
 *   php bin/api-docs.php serve [port]  — تشغيل خادم Swagger UI محلي
 */

$openapiFile = __DIR__ . '/../openapi.yaml';

if (!file_exists($openapiFile)) {
    fwrite(STDERR, "❌ ملف openapi.yaml غير موجود في: {$openapiFile}\n");
    exit(1);
}

$command = $argv[1] ?? 'help';

switch ($command) {
    case 'validate':
        validate($openapiFile);
        break;
    case 'stats':
        stats($openapiFile);
        break;
    case 'routes':
        routes($openapiFile);
        break;
    case 'serve':
        serve($openapiFile, (int)($argv[2] ?? 8080));
        break;
    default:
        help();
        break;
}

// ── الدوال ──────────────────────────────────────────────────────

function help(): void {
    echo <<<HELP
    
  📘 ABDO-TECK POS — API Documentation Tool
  ==========================================

  الأوامر المتاحة:
    validate    التحقق من صحة ملف openapi.yaml (بنية YAML + مراجع)
    stats       عرض إحصائيات التوثيق (عدد المسارات، الأساليب، المخططات)
    routes      عرض جميع المسارات الموثقة في جدول منسق
    serve       تشغيل خادم Swagger UI محلي (يحتاج PHP built-in server)
    help        عرض هذه المساعدة

  أمثلة:
    php bin/api-docs.php validate
    php bin/api-docs.php routes
    php bin/api-docs.php serve 9090

HELP;
}

function parseYaml(string $file): array {
    // محلل YAML بسيط — يعمل بدون إضافات خارجية
    // يدعم المستوى الأول والثاني فقط للتحقق الأساسي
    $content = file_get_contents($file);
    
    if (function_exists('yaml_parse')) {
        $parsed = yaml_parse($content);
        if ($parsed === false) {
            throw new RuntimeException("فشل تحليل ملف YAML");
        }
        return $parsed;
    }
    
    // fallback: تحليل بسيط بالـ regex
    $data = ['paths' => [], 'components' => ['schemas' => []]];
    
    // استخراج المسارات
    preg_match_all('/^  (\/[a-z\/{}\-]+):/m', $content, $pathMatches);
    $paths = $pathMatches[1] ?? [];
    
    foreach ($paths as $path) {
        $data['paths'][$path] = [];
        // استخراج الأساليب لكل مسار
        $escaped = preg_quote($path, '/');
        $pattern = "/^  {$escaped}:\s*\n((?:    (?:get|post|put|delete|patch):.*\n?)+)/m";
        if (preg_match($pattern, $content, $methodBlock)) {
            preg_match_all('/    (get|post|put|delete|patch):/m', $methodBlock[0], $methods);
            foreach (($methods[1] ?? []) as $method) {
                $data['paths'][$path][$method] = true;
            }
        }
    }
    
    // استخراج المخططات
    preg_match_all('/^    (\w+):\s*$/m', $content, $schemaMatches);
    if (!empty($schemaMatches[1])) {
        foreach ($schemaMatches[1] as $schema) {
            $data['components']['schemas'][$schema] = true;
        }
    }
    
    return $data;
}

function validate(string $file): void {
    echo "🔍 التحقق من صحة: {$file}\n\n";
    
    $content = file_get_contents($file);
    $errors = [];
    $warnings = [];
    
    // 1. التحقق من الترويسة
    if (!str_contains($content, 'openapi:')) {
        $errors[] = "حقل 'openapi' مفقود";
    }
    if (!str_contains($content, 'info:')) {
        $errors[] = "حقل 'info' مفقود";
    }
    if (!str_contains($content, 'paths:')) {
        $errors[] = "حقل 'paths' مفقود";
    }
    
    // 2. التحقق من المراجع ($ref)
    preg_match_all('/\$ref:\s*[\'"]?(#\/[^\'"\s]+)[\'"]?/', $content, $refs);
    foreach (($refs[1] ?? []) as $ref) {
        $parts = explode('/', ltrim($ref, '#/'));
        $search = end($parts);
        if (!str_contains($content, $search . ':')) {
            $warnings[] = "مرجع قد يكون غير صالح: {$ref}";
        }
    }
    
    // 3. التحقق من الأساليب HTTP
    preg_match_all('/^\s{4}(get|post|put|delete|patch):/m', $content, $methods);
    $methodCount = count($methods[1] ?? []);
    
    // 4. التحقق من وجود tags
    if (!str_contains($content, 'tags:')) {
        $warnings[] = "لا توجد تصنيفات (tags) للمسارات";
    }
    
    // النتائج
    if (empty($errors)) {
        echo "  ✅ البنية الأساسية صحيحة\n";
        echo "  ✅ تم العثور على {$methodCount} عملية API\n";
        
        $refCount = count($refs[1] ?? []);
        echo "  ✅ تم العثور على {$refCount} مرجع (\$ref)\n";
    }
    
    foreach ($warnings as $w) {
        echo "  ⚠️  {$w}\n";
    }
    foreach ($errors as $e) {
        echo "  ❌ {$e}\n";
    }
    
    echo "\n" . (empty($errors) ? "✅ التحقق ناجح!\n" : "❌ توجد أخطاء يجب إصلاحها.\n");
    exit(empty($errors) ? 0 : 1);
}

function stats(string $file): void {
    $content = file_get_contents($file);
    
    // عد المسارات
    preg_match_all('/^  \/[a-z\/{}\-]+:/m', $content, $paths);
    $pathCount = count(array_unique($paths[0] ?? []));
    
    // عد العمليات
    preg_match_all('/^\s{4}(get|post|put|delete|patch):/m', $content, $methods);
    $methodCounts = array_count_values($methods[1] ?? []);
    $totalOps = array_sum($methodCounts);
    
    // عد المخططات
    preg_match_all('/^\s{4}(\w+):\s*$/m', $content, $schemas);
    
    // عد التصنيفات
    preg_match_all('/^  - name: (.+)$/m', $content, $tags);
    
    echo "\n  📊 إحصائيات وثائق API\n";
    echo "  " . str_repeat('═', 40) . "\n";
    echo "  📁 المسارات (Paths):     {$pathCount}\n";
    echo "  🔧 العمليات (Operations): {$totalOps}\n";
    
    foreach (['get' => 'GET', 'post' => 'POST', 'put' => 'PUT', 'delete' => 'DELETE'] as $k => $label) {
        $c = $methodCounts[$k] ?? 0;
        if ($c > 0) echo "     ├─ {$label}: {$c}\n";
    }
    
    echo "  🏷️  التصنيفات (Tags):    " . count($tags[1] ?? []) . "\n";
    echo "  📐 المخططات (Schemas):   " . count(array_unique($schemas[1] ?? [])) . "\n";
    echo "  " . str_repeat('═', 40) . "\n\n";
}

function routes(string $file): void {
    $content = file_get_contents($file);
    
    echo "\n  📋 المسارات الموثقة\n";
    echo "  " . str_repeat('─', 60) . "\n";
    printf("  %-8s %-30s %s\n", "METHOD", "PATH", "SUMMARY");
    echo "  " . str_repeat('─', 60) . "\n";
    
    // استخراج كل مسار وأساليبه
    $lines = explode("\n", $content);
    $currentPath = null;
    $currentMethod = null;
    
    foreach ($lines as $line) {
        // مسار جديد (مسافتان بادئتان)
        if (preg_match('/^  (\/[a-z\/{}\-]+):/', $line, $m)) {
            $currentPath = $m[1];
            continue;
        }
        // أسلوب HTTP (4 مسافات)
        if (preg_match('/^    (get|post|put|delete|patch):/', $line, $m)) {
            $currentMethod = strtoupper($m[1]);
            continue;
        }
        // ملخص
        if ($currentPath && $currentMethod && preg_match('/^\s+summary:\s*(.+)/', $line, $m)) {
            $color = match($currentMethod) {
                'GET'    => "\033[32m",  // أخضر
                'POST'   => "\033[33m",  // أصفر
                'PUT'    => "\033[34m",  // أزرق
                'DELETE' => "\033[31m",  // أحمر
                default  => "\033[0m",
            };
            $reset = "\033[0m";
            printf("  {$color}%-8s{$reset} %-30s %s\n", $currentMethod, $currentPath, trim($m[1]));
            $currentMethod = null;
        }
    }
    
    echo "  " . str_repeat('─', 60) . "\n\n";
}

function serve(string $file, int $port): void {
    echo "\n  🚀 تشغيل Swagger UI على http://localhost:{$port}\n";
    echo "  📄 ملف OpenAPI: {$file}\n";
    echo "  اضغط Ctrl+C للإيقاف\n\n";
    
    // إنشاء ملف HTML مؤقت لعرض Swagger UI
    $tmpDir = sys_get_temp_dir() . '/pos-api-docs';
    @mkdir($tmpDir, 0755, true);
    
    // نسخ ملف OpenAPI
    copy($file, $tmpDir . '/openapi.yaml');
    
    // إنشاء صفحة Swagger UI
    file_put_contents($tmpDir . '/index.html', <<<HTML
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
      <meta charset="UTF-8">
      <title>ABDO-TECK POS — API Docs</title>
      <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    </head>
    <body>
      <div id="swagger-ui"></div>
      <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
      <script>
        SwaggerUIBundle({
          url: '/openapi.yaml',
          dom_id: '#swagger-ui',
          deepLinking: true,
          presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
          layout: 'BaseLayout'
        })
      </script>
    </body>
    </html>
    HTML);
    
    // تشغيل خادم PHP المدمج
    $cmd = PHP_BINARY . " -S localhost:{$port} -t " . escapeshellarg($tmpDir);
    passthru($cmd);
}
