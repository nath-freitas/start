<?php
header('Content-Type: application/json; charset=utf-8');
$dataFile = '/home/u346131448/dados/med-plano.json';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $in = json_decode(file_get_contents('php://input'), true);
  $plano = (is_array($in) && isset($in['plano']) && is_array($in['plano'])) ? $in['plano'] : [];
  @mkdir(dirname($dataFile), 0755, true);
  $payload = json_encode(['plano' => $plano, 'saved_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (@file_put_contents($dataFile, $payload, LOCK_EX) === false) { http_response_code(500); echo json_encode(['error'=>'write_failed']); exit; }
  echo $payload; exit;
}
echo is_file($dataFile) ? file_get_contents($dataFile) : json_encode(['plano' => []]);
