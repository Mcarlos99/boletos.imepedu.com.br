<?php
/**
 * ARQUIVO: admin/documentos.php
 * Página administrativa para gestão de documentos dos alunos
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../src/DocumentosService.php';
require_once 'includes/verificar-permissao.php';

$documentosService = new DocumentosService();
$estatisticas = $documentosService->getEstatisticasDocumentos();
$tiposDocumentos = DocumentosService::getTiposDocumentos();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Documentos - Administração IMEPEDU</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
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
        
        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stats-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0,102,204,0.05);
        }
        
        .badge-status {
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-pendente { background: rgba(255,193,7,0.1); color: #856404; }
        .badge-aprovado { background: rgba(40,167,69,0.1); color: #28a745; }
        .badge-rejeitado { background: rgba(220,53,69,0.1); color: #dc3545; }
        
        .btn-group-sm .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }
        
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2001;
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
            <small>Gestão de Documentos</small>
        </div>
        
        <nav class="sidebar-nav">
            <div class="nav-item">
                <a href="/admin/dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/boletos.php" class="nav-link">
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
                <a href="/admin/documentos.php" class="nav-link active">
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
                <h3>Gestão de Documentos</h3>
                <small class="text-muted">Controle e aprovação de documentos dos alunos</small>
            </div>
            <div>
                <button class="btn btn-primary" onclick="atualizarEstatisticas()">
                    <i class="fas fa-sync-alt"></i> Atualizar
                </button>
                <button class="btn btn-outline-secondary" onclick="exportarRelatorio()">
                    <i class="fas fa-download"></i> Exportar
                </button>
            </div>
        </div>
        
        <!-- Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-primary" id="totalDocumentos">
                        <?= $estatisticas['geral']['total_documentos'] ?? 0 ?>
                    </div>
                    <div class="stats-label">Total de Documentos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-warning" id="documentosPendentes">
                        <?= $estatisticas['geral']['pendentes'] ?? 0 ?>
                    </div>
                    <div class="stats-label">Pendentes</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-success" id="documentosAprovados">
                        <?= $estatisticas['geral']['aprovados'] ?? 0 ?>
                    </div>
                    <div class="stats-label">Aprovados</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-danger" id="documentosRejeitados">
                        <?= $estatisticas['geral']['rejeitados'] ?? 0 ?>
                    </div>
                    <div class="stats-label">Rejeitados</div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card">
            <div class="card-body">
                <h6 class="card-title mb-3">
                    <i class="fas fa-filter"></i> Filtros de Busca
                </h6>
                
                <form id="filtrosForm" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select" id="filtroStatus">
                            <option value="pendente" selected>Pendentes</option>
                            <option value="aprovado">Aprovados</option>
                            <option value="rejeitado">Rejeitados</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Tipo de Documento</label>
                        <select name="tipo" class="form-select" id="filtroTipo">
                            <option value="">Todos os tipos</option>
                            <?php foreach ($tiposDocumentos as $tipo => $info): ?>
                                <option value="<?= $tipo ?>"><?= htmlspecialchars($info['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" id="filtroDataInicio">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" id="filtroDataFim">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Itens por Página</label>
                        <select name="limite" class="form-select" id="filtroLimite">
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid">
                            <button type="button" class="btn btn-primary" onclick="aplicarFiltros()">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Documentos -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Documentos</h5>
                <span class="badge bg-primary" id="totalResultados">0 documentos</span>
            </div>
            <div class="card-body">
                <div id="documentosContainer">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                        <p class="mt-2">Carregando documentos...</p>
                    </div>
                </div>
                
                <!-- Paginação -->
                <nav aria-label="Paginação" class="mt-4" id="paginacaoContainer" style="display: none;">
                    <ul class="pagination justify-content-center" id="paginacao">
                    </ul>
                </nav>
            </div>
        </div>
    </div>
    
    <!-- Modal para visualizar documento -->
    <div class="modal fade" id="visualizarDocumentoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Documento do Aluno</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="documentoVisualizacao">
                    <!-- Conteúdo carregado dinamicamente -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
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
        let documentosData = [];
        let filtrosAtuais = {};
        let paginaAtual = 1;
        
        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Página de documentos carregada');
            carregarDocumentos();
            
            // Filtros em tempo real
            document.getElementById('filtroStatus').addEventListener('change', aplicarFiltros);
        });
        
        /**
         * Carrega documentos baseado nos filtros
         */
        function carregarDocumentos(pagina = 1) {
            paginaAtual = pagina;
            
            const params = new URLSearchParams({
                status: document.getElementById('filtroStatus').value,
                pagina: pagina,
                limite: document.getElementById('filtroLimite').value
            });
            
            // Adiciona filtros opcionais
            const tipo = document.getElementById('filtroTipo').value;
            if (tipo) params.append('tipo', tipo);
            
            const dataInicio = document.getElementById('filtroDataInicio').value;
            if (dataInicio) params.append('data_inicio', dataInicio);
            
            const dataFim = document.getElementById('filtroDataFim').value;
            if (dataFim) params.append('data_fim', dataFim);
            
            filtrosAtuais = Object.fromEntries(params);
            
            fetch(`/admin/api/listar-documentos-pendentes.php?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        documentosData = data.documentos;
                        exibirDocumentos(data);
                        atualizarPaginacao(data);
                    } else {
                        exibirErro(data.message);
                    }
                })
                .catch(error => {
                    console.error('Erro ao carregar documentos:', error);
                    exibirErro('Erro de conexão');
                });
        }
        
        /**
         * Exibe os documentos na tabela
         */
        function exibirDocumentos(data) {
            const container = document.getElementById('documentosContainer');
            const { documentos, total } = data;
            
            document.getElementById('totalResultados').textContent = `${total} documento(s)`;
            
            if (documentos.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h5>Nenhum documento encontrado</h5>
                        <p class="text-muted">Não há documentos com os filtros selecionados</p>
                    </div>
                `;
                return;
            }
            
            let html = `
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Aluno</th>
                                <th>Documento</th>
                                <th>Arquivo</th>
                                <th>Data Upload</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            documentos.forEach(doc => {
                const statusClass = {
                    'pendente': 'warning',
                    'aprovado': 'success',
                    'rejeitado': 'danger'
                }[doc.status] || 'secondary';
                
                const statusIcon = {
                    'pendente': 'fas fa-clock',
                    'aprovado': 'fas fa-check',
                    'rejeitado': 'fas fa-times'
                }[doc.status] || 'fas fa-question';
                
                html += `
                    <tr data-documento-id="${doc.id}">
                        <td>
                            <div>
                                <strong>${doc.aluno.nome}</strong>
                                <br><small class="text-muted">CPF: ${formatarCPF(doc.aluno.cpf)}</small>
                                <br><small class="text-muted">Polo: ${doc.aluno.polo}</small>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <i class="${doc.tipo_info.icone} text-${statusClass} me-2"></i>
                                <div>
                                    <strong>${doc.tipo_info.nome}</strong>
                                    ${doc.tipo_info.obrigatorio ? '<br><span class="badge bg-danger">Obrigatório</span>' : '<br><span class="badge bg-info">Opcional</span>'}
                                </div>
                            </div>
                        </td>
                        <td>
                            <div>
                                <strong>${doc.nome_original}</strong>
                                <br><small class="text-muted">${doc.tamanho_formatado}</small>
                            </div>
                        </td>
                        <td>
                            <div>
                                ${doc.data_upload_formatada}
                            </div>
                        </td>
                        <td>
                            <span class="badge-status badge-${doc.status}">
                                <i class="${statusIcon} me-1"></i>${doc.status.charAt(0).toUpperCase() + doc.status.slice(1)}
                            </span>
                            ${doc.observacoes ? `<br><small class="text-muted">${doc.observacoes}</small>` : ''}
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="downloadDocumento(${doc.id})" title="Download">
                                    <i class="fas fa-download"></i>
                                </button>
                                
                                <button class="btn btn-outline-info" onclick="visualizarDetalhes(${doc.id})" title="Ver detalhes">
                                    <i class="fas fa-eye"></i>
                                </button>
                                
                                ${doc.status === 'pendente' ? `
                                    <button class="btn btn-outline-success" onclick="aprovarDocumento(${doc.id})" title="Aprovar">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" onclick="rejeitarDocumento(${doc.id})" title="Rejeitar">
                                        <i class="fas fa-times"></i>
                                    </button>
                                ` : ''}
                                
                                <button class="btn btn-outline-secondary" onclick="removerDocumento(${doc.id})" title="Remover">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            container.innerHTML = html;
        }
        
        /**
         * Atualiza paginação
         */
        function atualizarPaginacao(data) {
            const { pagina, total_paginas } = data;
            const container = document.getElementById('paginacaoContainer');
            const paginacao = document.getElementById('paginacao');
            
            if (total_paginas <= 1) {
                container.style.display = 'none';
                return;
            }
            
            container.style.display = 'block';
            
            let html = '';
            
            // Página anterior
            if (pagina > 1) {
                html += `
                    <li class="page-item">
                        <a class="page-link" href="#" onclick="carregarDocumentos(${pagina - 1}); return false;">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                `;
            }
            
            // Páginas
            const inicio = Math.max(1, pagina - 2);
            const fim = Math.min(total_paginas, pagina + 2);
            
            for (let i = inicio; i <= fim; i++) {
                html += `
                    <li class="page-item ${i === pagina ? 'active' : ''}">
                        <a class="page-link" href="#" onclick="carregarDocumentos(${i}); return false;">
                            ${i}
                        </a>
                    </li>
                `;
            }
            
            // Próxima página
            if (pagina < total_paginas) {
                html += `
                    <li class="page-item">
                        <a class="page-link" href="#" onclick="carregarDocumentos(${pagina + 1}); return false;">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                `;
            }
            
            paginacao.innerHTML = html;
        }
        
        /**
         * Aplica filtros
         */
        function aplicarFiltros() {
            carregarDocumentos(1);
        }
        
        /**
         * Download de documento
         */
        function downloadDocumento(documentoId) {
            showToast('Preparando download...', 'info');
            
            const link = document.createElement('a');
            link.href = `/api/download-documento.php?id=${documentoId}`;
            link.target = '_blank';
            link.click();
            
            setTimeout(() => {
                showToast('Download iniciado!', 'success');
            }, 500);
        }
        
        /**
         * Visualizar detalhes do documento
         */
        function visualizarDetalhes(documentoId) {
            const documento = documentosData.find(d => d.id === documentoId);
            if (!documento) return;
            
            const modal = new bootstrap.Modal(document.getElementById('visualizarDocumentoModal'));
            const conteudo = document.getElementById('documentoVisualizacao');
            
            conteudo.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Informações do Aluno</h6>
                        <p><strong>Nome:</strong> ${documento.aluno.nome}</p>
                        <p><strong>CPF:</strong> ${formatarCPF(documento.aluno.cpf)}</p>
                        <p><strong>Email:</strong> ${documento.aluno.email}</p>
                        <p><strong>Polo:</strong> ${documento.aluno.polo}</p>
                    </div>
                    <div class="col-md-6">
                        <h6>Informações do Documento</h6>
                        <p><strong>Tipo:</strong> ${documento.tipo_info.nome}</p>
                        <p><strong>Arquivo:</strong> ${documento.nome_original}</p>
                        <p><strong>Tamanho:</strong> ${documento.tamanho_formatado}</p>
                        <p><strong>Upload:</strong> ${documento.data_upload_formatada}</p>
                        <p><strong>Status:</strong> <span class="badge-status badge-${documento.status}">${documento.status}</span></p>
                        ${documento.observacoes ? `<p><strong>Observações:</strong> ${documento.observacoes}</p>` : ''}
                    </div>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                    <button class="btn btn-primary" onclick="downloadDocumento(${documento.id})">
                        <i class="fas fa-download"></i> Download
                    </button>
                    ${documento.status === 'pendente' ? `
                        <button class="btn btn-success" onclick="aprovarDocumento(${documento.id}); bootstrap.Modal.getInstance(document.getElementById('visualizarDocumentoModal')).hide();">
                            <i class="fas fa-check"></i> Aprovar
                        </button>
                        <button class="btn btn-danger" onclick="rejeitarDocumento(${documento.id}); bootstrap.Modal.getInstance(document.getElementById('visualizarDocumentoModal')).hide();">
                            <i class="fas fa-times"></i> Rejeitar
                        </button>
                    ` : ''}
                </div>
            `;
            
            modal.show();
        }
        
        /**
         * Aprova documento
         */
        function aprovarDocumento(documentoId) {
            const observacoes = prompt('Observações sobre a aprovação (opcional):');
            
            if (observacoes !== null) {
                atualizarStatusDocumento(documentoId, 'aprovado', observacoes || '');
            }
        }
        
        /**
         * Rejeita documento
         */
        function rejeitarDocumento(documentoId) {
            const observacoes = prompt('Motivo da rejeição (obrigatório):');
            
            if (observacoes && observacoes.trim() !== '') {
                atualizarStatusDocumento(documentoId, 'rejeitado', observacoes.trim());
            } else if (observacoes !== null) {
                showToast('Motivo da rejeição é obrigatório', 'error');
            }
        }
        
        /**
         * Atualiza status do documento
         */
        function atualizarStatusDocumento(documentoId, status, observacoes) {
            showToast('Atualizando documento...', 'info');
            
            fetch('/admin/api/atualizar-documento.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    documento_id: documentoId,
                    status: status,
                    observacoes: observacoes
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`Documento ${status}!`, 'success');
                    setTimeout(() => {
                        carregarDocumentos(paginaAtual);
                        atualizarEstatisticas();
                    }, 1000);
                } else {
                    showToast('Erro: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erro ao atualizar documento:', error);
                showToast('Erro de conexão', 'error');
            });
        }
        
        /**
         * Remove documento
         */
        function removerDocumento(documentoId) {
            if (confirm('Tem certeza que deseja remover este documento? Esta ação não pode ser desfeita.')) {
                showToast('Removendo documento...', 'info');
                
                fetch('/admin/api/remover-documento.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        documento_id: documentoId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Documento removido!', 'success');
                        setTimeout(() => {
                            carregarDocumentos(paginaAtual);
                            atualizarEstatisticas();
                        }, 1000);
                    } else {
                        showToast('Erro: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Erro ao remover documento:', error);
                    showToast('Erro de conexão', 'error');
                });
            }
        }
        
        /**
         * Atualiza estatísticas
         */
        function atualizarEstatisticas() {
            fetch('/admin/api/estatisticas-documentos.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const stats = data.estatisticas.geral;
                        document.getElementById('totalDocumentos').textContent = stats.total_documentos || 0;
                        document.getElementById('documentosPendentes').textContent = stats.pendentes || 0;
                        document.getElementById('documentosAprovados').textContent = stats.aprovados || 0;
                        document.getElementById('documentosRejeitados').textContent = stats.rejeitados || 0;
                    }
                })
                .catch(error => {
                    console.error('Erro ao atualizar estatísticas:', error);
                });
        }
        
        /**
         * Exporta relatório
         */
        function exportarRelatorio() {
            const params = new URLSearchParams(filtrosAtuais);
            params.append('export', 'csv');
            
            showToast('Preparando exportação...', 'info');
            
            const link = document.createElement('a');
            link.href = `/admin/api/exportar-documentos.php?${params.toString()}`;
            link.download = `documentos_${new Date().toISOString().split('T')[0]}.csv`;
            link.click();
            
            setTimeout(() => {
                showToast('Exportação iniciada!', 'success');
            }, 1000);
        }
        
        /**
         * Formatar CPF
         */
        function formatarCPF(cpf) {
            return cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
        }
        
        /**
         * Exibir erro
         */
        function exibirErro(mensagem) {
            const container = document.getElementById('documentosContainer');
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <h5>Erro ao Carregar Documentos</h5>
                    <p class="text-muted">${mensagem}</p>
                    <button class="btn btn-primary" onclick="carregarDocumentos()">
                        <i class="fas fa-redo"></i> Tentar Novamente
                    </button>
                </div>
            `;
        }
        
        /**
         * Sistema de notificações
         */
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : 'primary'} border-0`;
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');
            
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            container.appendChild(toast);
            
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            toast.addEventListener('hidden.bs.toast', () => {
                container.removeChild(toast);
            });
        }
        
        console.log('✅ Sistema de Gestão de Documentos carregado!');
    </script>
</body>
</html>