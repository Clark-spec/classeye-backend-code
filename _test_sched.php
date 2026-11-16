<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$admin = App\Models\User::where("role", "admin")->first();
$token = $admin->createToken("test")->plainTextToken;

$ch = curl_init("http://127.0.0.1:8000/api/schedules");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Accept: application/json",
    "Authorization: Bearer " . $token,
]);
$res = curl_exec($ch);
$data = json_decode($res, true);
echo "Schedules count: " . count($data["data"] ?? []) . PHP_EOL;
foreach ($data["data"] ?? [] as $s) {
    echo "- " . $s["subject_code"] . " | " . $s["room"]["name"] . " | " . $s["instructor"]["name"] . " | Days: " . implode(',', $s['days']) . " | " . $s['start_time'] . "-" . $s['end_time'] . PHP_EOL;
}
