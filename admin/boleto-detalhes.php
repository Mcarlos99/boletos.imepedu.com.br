<?php
/**
 * Sistema de Boletos IMEPEDU - Página de Detalhes do Boleto
 * Arquivo: admin/boleto-detalhes.php
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/moodle.php';
require_once '../src/AdminService.php';
require_once 'includes/verificar-permissao.php';

$adminService = new AdminService();
$admin = $adminService->buscarAdminPorId($_SESSION['admin_id']);

if (!$admin) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

// Verifica se foi passado o ID do boleto
$boletoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$boletoId) {
    header('Location: /admin/boletos.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes do Boleto - Administração IMEPEDU</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #004499;
            --sidebar-width: 260px;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-color), var(--secondary-color));
            color: white;
            z-index: 1000;
            transition: all 0.3s ease;
            overflow-y: auto;
        }
        
        .sidebar-brand {
            padding: 1.5rem 1rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-brand h4 {
            margin: 0;
            font-weight: 600;
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .nav-item {
            margin: 0.25rem 0;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
            transform: translateX(5px);
        }
        
        .nav-link i {
            width: 20px;
            margin-right: 10px;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .loading-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 400px;
        }
        
        .badge-status {
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-pago { background: rgba(40,167,69,0.1); color: #28a745; }
        .badge-pendente { background: rgba(255,193,7,0.1); color: #856404; }
        .badge-vencido { background: rgba(220,53,69,0.1); color: #dc3545; }
        .badge-cancelado { background: rgba(108,117,125,0.1); color: #6c757d; }
        
        .alert-info {
            background-color: rgba(13, 202, 240, 0.1);
            border-color: rgba(13, 202, 240, 0.2);
            color: #055160;
        }
        
        .btn-group .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h4><i class="fas fa-graduation-cap"></i> IMEPEDU Admin</h4>
            <small>Sistema de Boletos</small>
        </div>
        
        <nav class="sidebar-nav">
            <div class="nav-item">
                <a href="/admin/dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/boletos.php" class="nav-link active">
                    <i class="fas fa-file-invoice-dollar"></i>
                    Gerenciar Boletos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/upload-boletos.php" class="nav-link">
                    <i class="fas fa-upload"></i>
                    Upload de Boletos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/alunos.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    Alunos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/documentos.php" class="nav-link">
                    <i class="fas fa-folder-open"></i>
                    Documentos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/cursos.php" class="nav-link">
                    <i class="fas fa-book"></i>
                    Cursos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/relatorios.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    Relatórios
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/configuracoes.php" class="nav-link">
                    <i class="fas fa-cog"></i>
                    Configurações
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/logs.php" class="nav-link">
                    <i class="fas fa-list-alt"></i>
                    Logs do Sistema
                </a>
            </div>
            
            <hr style="border-color: rgba(255,255,255,0.1); margin: 1rem 0;">
            
            <div class="nav-item">
                <a href="/admin/logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    Sair
                </a>
            </div>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3>Detalhes do Boleto</h3>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="/admin/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="/admin/boletos.php">Boletos</a></li>
                        <li class="breadcrumb-item active">Detalhes #<?= $boletoId ?></li>
                    </ol>
                </nav>
            </div>
            <div>
                <a href="/admin/boletos.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Container de Detalhes -->
        <div class="card">
            <div class="card-body">
                <div id="detalhesContainer">
                    <div class="loading-container">
                        <div class="text-center">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Carregando...</span>
                            </div>
                            <p class="mt-3">Carregando detalhes do boleto...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para marcar como pago -->
    <div class="modal fade" id="pagoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Marcar como Pago</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="pagoForm">
                        <input type="hidden" id="pago_boleto_id">
                        
                        <div class="mb-3">
                            <label for="valor_pago" class="form-label">Valor Pago</label>
                            <input type="number" class="form-control" id="valor_pago" step="0.01" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="data_pagamento" class="form-label">Data do Pagamento</label>
                            <input type="date" class="form-control" id="data_pagamento" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="observacoes_pago" class="form-label">Observações</label>
                            <textarea class="form-control" id="observacoes_pago" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" onclick="confirmarPagamento()">
                        <i class="fas fa-check"></i> Confirmar Pagamento
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // Variáveis globais
        const boletoId = <?= $boletoId ?>;
        let boletoData = null;
        
        // Carrega detalhes do boleto na inicialização
        document.addEventListener('DOMContentLoaded', function() {
            carregarDetalhes();
        });
        
        // Função para carregar detalhes do boleto
        function carregarDetalhes() {
            console.log('Carregando detalhes do boleto:', boletoId);
            
            fetch(`/admin/api/boleto-detalhes.php?id=${boletoId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        boletoData = data;
                        exibirDetalhes(data);
                        //showToast('Detalhes carregados com sucesso!', 'success');
                    } else {
                        exibirErro('Erro ao carregar detalhes: ' + (data.message || 'Erro desconhecido'));
                        showToast('Erro: ' + (data.message || 'Erro desconhecido'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Erro ao buscar detalhes:', error);
                    exibirErro('Erro de conexão: ' + error.message);
                    showToast('Erro de conexão', 'error');
                });
        }
        
        // Exibe detalhes do boleto
        function exibirDetalhes(data) {
            const { boleto, aluno, curso, administrador, arquivo, estatisticas, historico, acoes_disponiveis } = data;
            
            // Determina classe de status
            const statusClass = {
                'pago': 'success',
                'pendente': 'warning', 
                'vencido': 'danger',
                'cancelado': 'secondary'
            }[boleto.status] || 'secondary';
            
            const html = `
                <!-- Header com Status -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h4 class="mb-1">
                            <i class="fas fa-file-invoice-dollar text-primary"></i>
                            Boleto #${boleto.numero_boleto}
                        </h4>
                        <span class="badge bg-${statusClass} fs-6">${boleto.status_label}</span>
                        ${boleto.esta_vencido ? '<span class="badge bg-danger ms-2">VENCIDO</span>' : ''}
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="h4 text-primary mb-0">${boleto.valor_formatado}</div>
                        <small class="text-muted">${boleto.status_vencimento}</small>
                    </div>
                </div>

                <!-- Informações Principais -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-info-circle"></i> Informações do Boleto
                        </h6>
                        <table class="table table-sm">
                            <tr>
                                <td><strong>Número:</strong></td>
                                <td>#${boleto.numero_boleto}</td>
                            </tr>
                            <tr>
                                <td><strong>Valor:</strong></td>
                                <td>${boleto.valor_formatado}</td>
                            </tr>
                            <tr>
                                <td><strong>Vencimento:</strong></td>
                                <td>${boleto.vencimento_formatado}</td>
                            </tr>
                            <tr>
                                <td><strong>Status:</strong></td>
                                <td><span class="badge bg-${statusClass}">${boleto.status_label}</span></td>
                            </tr>
                            ${boleto.data_pagamento_formatada ? `
                            <tr>
                                <td><strong>Data Pagamento:</strong></td>
                                <td>${boleto.data_pagamento_formatada}</td>
                            </tr>
                            ` : ''}
                            ${boleto.valor_pago_formatado ? `
                            <tr>
                                <td><strong>Valor Pago:</strong></td>
                                <td>${boleto.valor_pago_formatado}</td>
                            </tr>
                            ` : ''}
                            <tr>
                                <td><strong>Criado em:</strong></td>
                                <td>${boleto.created_at_formatado}</td>
                            </tr>
                        </table>
                        
                        ${boleto.descricao && boleto.descricao !== 'Sem descrição' ? `
                        <div class="mt-3">
                            <strong>Descrição:</strong>
                            <p class="text-muted">${boleto.descricao}</p>
                        </div>
                        ` : ''}
                        
                        ${boleto.observacoes ? `
                        <div class="mt-3">
                            <strong>Observações:</strong>
                            <p class="text-muted">${boleto.observacoes}</p>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-user"></i> Informações do Aluno
                        </h6>
                        <table class="table table-sm">
                            <tr>
                                <td><strong>Nome:</strong></td>
                                <td>${aluno.nome}</td>
                            </tr>
                            <tr>
                                <td><strong>CPF:</strong></td>
                                <td>${aluno.cpf_formatado}</td>
                            </tr>
                            <tr>
                                <td><strong>Email:</strong></td>
                                <td>
                                    <a href="mailto:${aluno.email}" class="text-decoration-none">
                                        ${aluno.email}
                                    </a>
                                </td>
                            </tr>
                            ${aluno.cidade ? `
                            <tr>
                                <td><strong>Cidade:</strong></td>
                                <td>${aluno.cidade}</td>
                            </tr>
                            ` : ''}
                            <tr>
                                <td><strong>Cadastro:</strong></td>
                                <td>${aluno.cadastro_formatado}</td>
                            </tr>
                        </table>
                        
                        <h6 class="text-primary mb-3 mt-4">
                            <i class="fas fa-graduation-cap"></i> Curso
                        </h6>
                        <table class="table table-sm">
                            <tr>
                                <td><strong>Nome:</strong></td>
                                <td>${curso.nome}</td>
                            </tr>
                            <tr>
                                <td><strong>Código:</strong></td>
                                <td>${curso.nome_curto}</td>
                            </tr>
                            <tr>
                                <td><strong>Polo:</strong></td>
                                <td>${curso.polo_nome}</td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Informações do Arquivo -->
                ${arquivo ? `
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-file-pdf"></i> Arquivo PDF
                        </h6>
                        ${arquivo.existe ? `
                        <div class="alert alert-success">
                            <div class="row">
                                <div class="col-md-8">
                                    <strong><i class="fas fa-check-circle"></i> Arquivo disponível</strong>
                                    <br><small>Tamanho: ${arquivo.tamanho_formatado}</small>
                                    <br><small>Modificado: ${arquivo.data_modificacao}</small>
                                </div>
                                <div class="col-md-4 text-end">
                                    <button class="btn btn-info btn-sm" onclick="downloadBoleto(${boleto.id})">
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                </div>
                            </div>
                        </div>
                        ` : `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Arquivo não encontrado</strong>
                            <br><small>${arquivo.erro}</small>
                        </div>
                        `}
                    </div>
                </div>
                ` : ''}

                <!-- Estatísticas -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-chart-bar"></i> Estatísticas
                        </h6>
                        <div class="row text-center">
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1">${estatisticas.total_downloads}</h6>
                                        <small class="text-muted">Downloads</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1">${estatisticas.total_pix_gerados}</h6>
                                        <small class="text-muted">PIX Gerados</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <h6 class="card-title mb-1">${estatisticas.ultimo_download_formatado}</h6>
                                        <small class="text-muted">Último Download</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Histórico de Ações -->
                ${historico && historico.length > 0 ? `
                <div class="row mb-4">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-history"></i> Histórico de Ações
                        </h6>
                        <div style="max-height: 200px; overflow-y: auto;">
                            ${historico.map(log => `
                            <div class="border-bottom py-2">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong>${getTipoLabel(log.tipo)}</strong>
                                        <br><small class="text-muted">${log.descricao}</small>
                                        <br><small class="text-info">por ${log.admin_nome}</small>
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted">${log.data_formatada}</small>
                                    </div>
                                </div>
                            </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Ações Disponíveis -->
                <div class="row">
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-cogs"></i> Ações Disponíveis
                        </h6>
                        <div class="btn-group flex-wrap">
                            ${acoes_disponiveis.map(acao => `
                            <button class="btn ${acao.classe}" onclick="executarAcao('${acao.tipo}', ${boleto.id})">
                                <i class="${acao.icone}"></i> ${acao.label}
                            </button>
                            `).join('')}
                        </div>
                    </div>
                </div>

                <!-- Informações do Administrador -->
                <div class="row mt-4">
                    <div class="col-12">
                        <small class="text-muted">
                            <i class="fas fa-user-shield"></i>
                            Criado por: ${administrador.nome}
                            ${administrador.email ? ` (${administrador.email})` : ''}
                        </small>
                    </div>
                </div>
            `;
            
            document.getElementById('detalhesContainer').innerHTML = html;
        }
        
        // Exibe erro
        function exibirErro(mensagem) {
            const html = `
                <div class="text-center p-4">
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <h5 class="text-danger">Erro ao Carregar Detalhes</h5>
                    <p class="text-muted mb-4">${mensagem}</p>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" onclick="carregarDetalhes()">
                            <i class="fas fa-redo"></i> Tentar Novamente
                        </button>
                        <a href="/admin/boletos.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Voltar aos Boletos
                        </a>
                    </div>
                </div>
            `;
            
            document.getElementById('detalhesContainer').innerHTML = html;
        }
        
        // Função auxiliar para labels dos tipos de log
        function getTipoLabel(tipo) {
            const labels = {
                'upload_individual': 'Upload Individual',
                'upload_lote': 'Upload em Lote',
                'boleto_pago': 'Marcado como Pago',
                'boleto_pago_admin': 'Pago pelo Admin',
                'boleto_cancelado': 'Cancelado',
                'boleto_cancelado_admin': 'Cancelado pelo Admin',
                'download_boleto': 'Download',
                'download_pdf_sucesso': 'Download PDF',
                'download_pdf_erro': 'Erro no Download',
                'pix_gerado': 'PIX Gerado',
                'pix_erro': 'Erro no PIX',
                'remover_boleto': 'Removido',
                'atualizar_arquivo': 'Arquivo Atualizado'
            };
            
            return labels[tipo] || tipo.replace(/_/g, ' ').toUpperCase();
        }
        
        // Executa ações do boleto
        function executarAcao(acao, boletoId) {
            switch (acao) {
                case 'download':
                    downloadBoleto(boletoId);
                    break;
                case 'pix':
                    mostrarPix(boletoId);
                    break;
                case 'marcar_pago':
                    marcarComoPago(boletoId);
                    break;
                case 'cancelar':
                    cancelarBoleto(boletoId);
                    break;
                case 'editar':
                    editarBoleto(boletoId);
                    break;
                case 'remover':
                    removerBoleto(boletoId);
                    break;
                default:
                    showToast('Ação não implementada: ' + acao, 'warning');
            }
        }
        
        // Download de boleto
        function downloadBoleto(boletoId) {
            console.log('Iniciando download do boleto:', boletoId);
            
            showToast('Preparando download...', 'info');
            
            // Cria link temporário para download
            const link = document.createElement('a');
            link.href = `/api/download-boleto.php?id=${boletoId}`;
            link.download = `Boleto_${boletoId}.pdf`;
            link.target = '_blank';
            
            // Simula clique para iniciar download
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Feedback para o usuário
            setTimeout(() => {
                showToast('Download iniciado!', 'success');
            }, 500);
        }
        
        // Marcar como pago
        function marcarComoPago(boletoId) {
            if (boletoData && boletoData.boleto) {
                document.getElementById('pago_boleto_id').value = boletoId;
                document.getElementById('valor_pago').value = boletoData.boleto.valor;
                document.getElementById('data_pagamento').value = new Date().toISOString().split('T')[0];
                
                const modal = new bootstrap.Modal(document.getElementById('pagoModal'));
                modal.show();
            } else {
                showToast('Erro ao carregar dados do boleto', 'error');
            }
        }
        
        // Confirma pagamento
        function confirmarPagamento() {
            const boletoId = document.getElementById('pago_boleto_id').value;
            const valorPago = document.getElementById('valor_pago').value;
            const dataPagamento = document.getElementById('data_pagamento').value;
            const observacoes = document.getElementById('observacoes_pago').value;
            
            if (!valorPago || !dataPagamento) {
                showToast('Preencha todos os campos obrigatórios', 'error');
                return;
            }
            
            if (parseFloat(valorPago) <= 0) {
                showToast('Valor deve ser maior que zero', 'error');
                return;
            }
            
            // Mostra loading
            const submitBtn = document.querySelector('#pagoModal .btn-success');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
            submitBtn.disabled = true;
            
            fetch('/admin/api/marcar-pago.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    boleto_id: parseInt(boletoId),
                    valor_pago: parseFloat(valorPago),
                    data_pagamento: dataPagamento,
                    observacoes: observacoes
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Boleto marcado como pago!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('pagoModal')).hide();
                    
                    // Recarrega os detalhes
                    setTimeout(() => carregarDetalhes(), 1000);
                } else {
                    showToast('Erro: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showToast('Erro de conexão', 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        // Cancelar boleto
        function cancelarBoleto(boletoId) {
            const motivo = prompt('Motivo do cancelamento (obrigatório):');
            
            if (motivo === null) {
                return; // Usuário cancelou
            }
            
            if (!motivo.trim()) {
                showToast('Motivo do cancelamento é obrigatório', 'error');
                return;
            }
            
            if (confirm('Tem certeza que deseja cancelar este boleto?\n\nEsta ação não pode ser desfeita.')) {
                showToast('Cancelando boleto...', 'info');
                
                fetch('/admin/api/cancelar-boleto.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        boleto_id: boletoId,
                        motivo: motivo.trim()
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Boleto cancelado com sucesso!', 'success');
                        setTimeout(() => carregarDetalhes(), 1000);
                    } else {
                        showToast('Erro: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    showToast('Erro de conexão', 'error');
                });
            }
        }
        
        // Remover boleto
        function removerBoleto(boletoId) {
            if (confirm('⚠️ ATENÇÃO: Remover Boleto\n\nEsta ação irá:\n• Excluir o boleto permanentemente\n• Remover o arquivo PDF do servidor\n• Não poderá ser desfeita\n\nTem certeza que deseja continuar?')) {
                const motivo = prompt('Motivo da remoção (obrigatório):') || 'Removido pelo administrador';
                
                if (!motivo.trim()) {
                    showToast('Motivo da remoção é obrigatório', 'error');
                    return;
                }
                
                showToast('Removendo boleto...', 'info');
                
                fetch('/admin/api/remover-boleto-simples.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        boleto_id: boletoId,
                        motivo: motivo.trim(),
                        confirmar_remocao: true
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Boleto removido com sucesso!', 'success');
                        setTimeout(() => {
                            window.location.href = '/admin/boletos.php';
                        }, 1500);
                    } else {
                        showToast('Erro: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    showToast('Erro de conexão', 'error');
                });
            }
        }
        
        // Editar boleto (placeholder)
        function editarBoleto(boletoId) {
            showToast('Funcionalidade de edição será implementada em breve', 'info');
        }
        
        // PIX (placeholder)
        function mostrarPix(boletoId) {
            showToast('Funcionalidade PIX será implementada em breve', 'info');
        }
        
        // Sistema de notificações
        function showToast(message, type = 'info') {
            const existingToasts = document.querySelectorAll('.toast-custom');
            existingToasts.forEach(toast => toast.remove());
            
            // Cria container se não existir
            let container = document.getElementById('toastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toastContainer';
                container.className = 'toast-container';
                container.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 2001;
                    min-width: 300px;
                    max-width: 90vw;
                `;
                document.body.appendChild(container);
            }
            
            const toast = document.createElement('div');
            toast.className = `toast-custom alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : 'info'} position-relative`;
            toast.style.cssText = 'animation: slideInRight 0.3s ease; margin-bottom: 8px;';
            
            const icon = type === 'error' ? 'fa-exclamation-triangle' : 
                        type === 'success' ? 'fa-check-circle' : 
                        type === 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle';
            
            toast.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas ${icon} me-2"></i>
                    <span class="flex-grow-1">${message}</span>
                    <button type="button" class="btn-close ms-2" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            
            container.appendChild(toast);
            
            // Remove automaticamente após 5 segundos
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => {
                        if (toast.parentNode) {
                            container.removeChild(toast);
                        }
                    }, 300);
                }
            }, 5000);
        }
        
        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            // F5 para recarregar detalhes
            if (e.key === 'F5' && !e.ctrlKey) {
                e.preventDefault();
                showToast('Atualizando detalhes...', 'info');
                setTimeout(() => carregarDetalhes(), 500);
            }
            
            // ESC para voltar
            if (e.key === 'Escape') {
                window.location.href = '/admin/boletos.php';
            }
            
            // Ctrl + D para download (se disponível)
            if (e.ctrlKey && e.key === 'd' && boletoData && boletoData.arquivo && boletoData.arquivo.existe) {
                e.preventDefault();
                downloadBoleto(boletoId);
            }
        });
        
        // Adiciona estilos para animações
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            
            .toast-container {
                position: fixed !important;
                top: 20px !important;
                right: 20px !important;
                z-index: 2001 !important;
            }
            
            .btn-group .btn {
                transition: all 0.2s ease;
            }
            
            .btn-group .btn:hover {
                transform: translateY(-1px);
            }
            
            .table tbody tr:hover {
                background-color: rgba(0,102,204,0.05);
            }
        `;
        document.head.appendChild(style);
        
        // Log de debug
        console.log('✅ Página de detalhes do boleto carregada!', {
            boleto_id: boletoId,
            timestamp: new Date().toISOString()
        });
    </script>
</body>
</html>