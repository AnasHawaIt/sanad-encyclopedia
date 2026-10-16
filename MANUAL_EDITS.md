1) routes/api.php: أضف في آخر الملف السطر:
   require __DIR__.'/api_v1.php';

2) bootstrap/app.php داخل ->withExceptions(function (Exceptions $exceptions) { ... }):
   $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());

3) .env و .env.example:
   APP_LOCALE=ar
   APP_FALLBACK_LOCALE=ar
