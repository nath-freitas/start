<?php
// Persistência do "Painel de Campanha — Imersão Coolhunting na Era da IA".
// Grava FORA da pasta publicada, para que nenhum deploy do Git apague o que o
// cliente/time preencher. O painel faz fetch('save.php') relativo a esta pasta.
$FILE = '/home/u346131448/dados/jg-painel-imersao-self-design.json';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
        exit;
    }
    $data['_saved_at'] = date('c');
    @mkdir(dirname($FILE), 0755, true);
    file_put_contents($FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    echo json_encode(['ok' => true, 'saved_at' => $data['_saved_at']]);
} else {
    echo file_exists($FILE) ? file_get_contents($FILE) : json_encode((object)[]);
}
