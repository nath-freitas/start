<?php
// Endpoint de salvamento do Business Model Canvas — JG Studio.
// Coloque este arquivo NA MESMA PASTA do canvas-modelo-negocio-jg-essencial.html.
// GET  -> devolve o conteúdo salvo.
// POST -> grava o conteúdo enviado e carimba a data/hora.

$FILE = __DIR__ . '/canvas_jg.json';   // arquivo próprio deste canvas
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
    file_put_contents($FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true, 'saved_at' => $data['_saved_at']]);
} else {
    echo file_exists($FILE) ? file_get_contents($FILE) : json_encode((object)[]);
}
