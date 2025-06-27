<?php
/**
 * Script para Atualizar Banco - Suporte Total à Hierarquia
 * Arquivo: atualizar_hierarquia_completa.php
 * 
 * Execute este script para garantir suporte completo à hierarquia de cursos
 */

require_once 'config/database.php';
require_once 'config/moodle.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atualização Hierarquia Completa - Sistema de Boletos IMED</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .step { margin: 20px 0; padding: 15px; border-radius: 5px; }
        .step.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .step.error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .step.info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .step.warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        .btn { background: #007bff; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #0056b3; }
        .btn.danger { background: #dc3545; }
        .btn.danger:hover { background: #c82333; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        .highlight { background: yellow; font-weight: bold; padding: 2px 4px; }
        .debug-box { background: #2d3748; color: #e2e8f0; padding: 15px; border-radius: 4px; font-family: monospace; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .teste-box { background: #e8f4f8; border: 1px solid #b8daff; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>