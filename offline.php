<?php
/** Shown by the service worker when a page is opened without a connection. Static: no sign-in, no database. */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=86400');
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>You are offline</title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 16px/1.5 system-ui, "Segoe UI", Arial, sans-serif; background: #f4f6fb; color: #172033; }
  main { max-width: 420px; padding: 32px 24px; text-align: center; }
  .ico { font-size: 56px; } h1 { margin: 8px 0; font-size: 24px; } p { color: #475467; }
  button { margin-top: 12px; padding: 12px 22px; border: 0; border-radius: 10px; background: #3651d4; color: #fff; font: inherit; font-weight: 600; cursor: pointer; min-height: 44px; }
  @media (prefers-color-scheme: dark) { body { background: #15172a; color: #e6e8f2; } p { color: #b6bdd0; } }
</style></head><body><main role="main"><div class="ico" aria-hidden="true">📡</div><h1>You are offline</h1>
<p>SmartClass needs an internet connection to show your classes and marks — nothing you did was lost. Check your connection and try again.</p>
<button type="button" onclick="location.reload()">Try again</button></main></body></html>
