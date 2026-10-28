<?php
/**
 * API PRINCIPAL PHP/MYSQL - BARBEARIA HONORATO (PRODUTIVO & ALTAMENTE SEGURO)
 * Este arquivo processa todas as requisições vindas do React e interage exclusivamente com o MySQL.
 */

// --------------------------------------------------------
// 19 - TRATAMENTO GLOBAL DE ERROS E EXCEÇÕES
// --------------------------------------------------------
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

set_exception_handler(function($e) {
    error_log($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    
    // Tenta gravar o log no banco de dados se a conexão estiver ativa
    global $conn;
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        try {
            $msg = $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $stmt = $conn->prepare("INSERT INTO logs (usuario_id, barbearia_id, acao, descricao, ip) VALUES ('system', 'default', 'error', ?, ?)");
            if ($stmt) {
                $stmt->bind_param("ss", $msg, $ip);
                $stmt->execute();
            }
        } catch (Exception $logEx) {
            // Ignora silenciosamente erros ao salvar log de erro
        }
    }
    
    http_response_code(500);
    echo json_encode([
        "error" => "Ocorreu um erro interno no servidor. Por favor, tente novamente mais tarde.",
        "message" => "Erro de sistema registrado nos logs."
    ]);
    exit();
});

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Inicialização da sessão segura
$isSecure = false;
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $isSecure = true;
} elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $isSecure = true;
}
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $isSecure ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
session_start();

// --------------------------------------------------------
// 14 - CONFIGURAÇÃO SEGURA DE CORS
// --------------------------------------------------------
$http_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$http_host = $_SERVER['HTTP_HOST'] ?? '';

if ($http_origin) {
    if (strpos($http_origin, $http_host) !== false || 
        strpos($http_origin, 'localhost') !== false || 
        strpos($http_origin, '127.0.0.1') !== false || 
        strpos($http_origin, 'run.app') !== false) {
        header("Access-Control-Allow-Origin: " . $http_origin);
    } else {
        header("Access-Control-Allow-Origin: https://" . $http_host);
    }
}
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Responde a requisições preflight OPTIONS instantaneamente
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db_config.php';

// --------------------------------------------------------
// DADOS DE SEED SEGUROS (SENHAS SERÃO CRIPTOGRAFADAS NO BANCO)
// --------------------------------------------------------
$initialAdmins = []; // Administradores devem ser cadastrados por processo seguro fora do código-fonte.

$defaultPayment = [
    "pixKey" => "nycollasteixeira30@gmail.com",
    "pixKeyType" => "email",
    "beneficiaryName" => "Honorato Barber Shop Ltda",
    "bankName" => "Banco Nu Pagamentos S.A. (Nubank)",
    "city" => "Uberlandia",
    "gatewayToken" => "",
    "gatewayProvider" => "manual"
];

function normalizeGatewayProvider($provider) {
    $normalized = strtolower(trim((string)$provider));
    return in_array($normalized, ['asaas', 'mercado_pago', 'manual'], true) ? $normalized : '';
}

function isGatewayProvider($provider) {
    return in_array(normalizeGatewayProvider($provider), ['asaas', 'mercado_pago'], true);
}

function isApprovedGatewayStatus($provider, $status) {
    $provider = normalizeGatewayProvider($provider);
    $status = trim((string)$status);
    if ($provider === 'asaas') {
        return in_array(strtoupper($status), ['CONFIRMED', 'RECEIVED'], true);
    }
    if ($provider === 'mercado_pago') {
        return strtolower($status) === 'approved';
    }
    return false;
}

// --------------------------------------------------------
// 21 - GARANTIR EXCLUSIVIDADE DO MYSQL (SEM FALLBACK JSON)
// --------------------------------------------------------
$conn = null;

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);
    if ($conn->connect_error) {
        throw new Exception("Falha na conexão com o banco de dados MySQL: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    
    // Atualiza e otimiza tabelas do banco de dados automaticamente
    setupMySQLTablesAndTenancy($conn);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Erro na conexao com o banco de dados. " . $e->getMessage()]);
    exit();
}

// --------------------------------------------------------
// SISTEMA DE ROTAS DA API
// --------------------------------------------------------
$route = isset($_GET['route']) ? trim($_GET['route'], '/') : '';
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// Remove query parameters extras da rota
$routeParts = explode('?', $route);
$route = $routeParts[0];

// Separa os segmentos da rota
$segments = explode('/', $route);
$resource = $segments[0];
$resourceId = isset($segments[1]) ? $segments[1] : null;

// 4 - VALIDAÇÃO GLOBAL DE CSRF (Métodos modificadores)
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    if (!verifyCSRFToken($input)) {
        http_response_code(403);
        echo json_encode(["error" => "Token CSRF invalido ou ausente. Operacao cancelada por seguranca."]);
        exit();
    }
}

// Multi-tenant: Identifica barbearia corrente
$tenantId = 'default';
if (isset($_SESSION['user']['barbearia_id'])) {
    $tenantId = $_SESSION['user']['barbearia_id'];
}

switch ($resource) {
    case 'csrf-token':
        handleCSRFTokenRoute();
        break;
    case 'session':
        handleSessionRoute();
        break;
    case 'login':
        handleLoginRoute($conn, $input);
        break;
    case 'logout':
        handleLogoutRoute($conn);
        break;
    case 'admins':
        handleAdminsRoute($conn, $method, $tenantId);
        break;
    case 'barbers':
        handleBarbersRoute($conn, $method, $resourceId, $input, $tenantId);
        break;
    case 'clients':
        handleClientsRoute($conn, $method, $resourceId, $input, $tenantId);
        break;
    case 'bookings':
        handleBookingsRoute($conn, $method, $resourceId, $input, $tenantId);
        break;
    case 'products':
        handleProductsRoute($conn, $method, $resourceId, $input, $tenantId);
        break;
    case 'drinks':
        handleDrinksRoute($conn, $method, $resourceId, $input, $tenantId);
        break;
    case 'orders':
        handleOrdersRoute($conn, $method, $input, $tenantId);
        break;
    case 'payment-settings':
        handlePaymentSettingsRoute($conn, $method, $input, $tenantId);
        break;
    case 'subscription-plans':
        handleSubscriptionPlansRoute($conn, $method, $resourceId, $input, $tenantId);
        break;
    case 'service-catalog':
        handleServiceCatalogRoute($conn, $method, $resourceId, $input, $tenantId);
        break;
    case 'payments':
        handlePaymentsRoute($conn, $method, $segments, $input, $tenantId);
        break;
    case 'upload':
        handleUploadRoute();
        break;
    case 'backup':
        handleBackupRoute($conn, $tenantId);
        break;
    case 'logs':
        handleLogsRoute($conn, $tenantId);
        break;
    case 'fidelity':
        handleFidelityRoute($conn, $method, $input, $tenantId);
        break;
    case 'stock-history':
        handleStockHistoryRoute($conn, $tenantId);
        break;
    case 'commission-payments':
        handleCommissionPaymentsRoute($conn, $method, $input, $tenantId);
        break;
    default:
        http_response_code(404);
        echo json_encode(["error" => "Rota nao encontrada: " . $route]);
        break;
}

// --------------------------------------------------------
// AUTORIZAÇÃO CENTRALIZADA NO BACKEND
// --------------------------------------------------------
function currentUser() {
    return $_SESSION['user'] ?? null;
}

function requireAuthenticated() {
    if (empty($_SESSION['user']['id'])) {
        http_response_code(401);
        echo json_encode(["error" => "Autenticacao obrigatoria."]);
        return false;
    }
    return true;
}

function requireRoles(array $roles) {
    if (!requireAuthenticated()) {
        return false;
    }
    $role = $_SESSION['user']['role'] ?? '';
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        echo json_encode(["error" => "Permissao negada."]);
        return false;
    }
    return true;
}

// --------------------------------------------------------
// EXECUTOR SEGURO DE CONSULTAS SQL (SEM FALHAS SILENCIOSAS)
// --------------------------------------------------------
function executeStmt($stmt) {
    if (!$stmt) {
        error_log("Erro de preparação SQL: o statement retornado pelo banco de dados é nulo ou inválido.");
        http_response_code(500);
        echo json_encode([
            "error" => "Não foi possível concluir a operação."
        ]);
        exit();
    }
    if (!$stmt->execute()) {
        $err = $stmt->error ?: "Erro interno na consulta";
        error_log("Erro de execução SQL: " . $err);
        if ($stmt->errno === 1062) {
            http_response_code(409);
            echo json_encode([
                "error" => "Já existe um cadastro com estas informações (como e-mail). Verifique os dados ou faça login."
            ]);
            exit();
        }
        http_response_code(500);
        echo json_encode([
            "error" => "Não foi possível concluir a operação."
        ]);
        exit();
    }
    return true;
}

// --------------------------------------------------------
// FUNÇÃO PARA REGISTRO DE AUDITORIA (LOGS)
// --------------------------------------------------------
function writeLog($conn, $usuario_id, $barbearia_id, $acao, $descricao) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt = $conn->prepare("INSERT INTO logs (usuario_id, barbearia_id, acao, descricao, ip) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sssss", $usuario_id, $barbearia_id, $acao, $descricao, $ip);
        if (!$stmt->execute()) {
            error_log("Falha ao registrar log de auditoria no banco: " . $stmt->error);
        }
    }
}

// --------------------------------------------------------
// PROTEÇÃO CONTRA CSRF
// --------------------------------------------------------
function verifyCSRFToken($input) {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($sessionToken)) {
        return false;
    }
    
    // Obter todos os cabeçalhos de forma resiliente
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    
    // Converter chaves de headers para caixa baixa para busca case-insensitive
    $lowercasedHeaders = [];
    foreach ($headers as $key => $val) {
        $lowercasedHeaders[strtolower($key)] = $val;
    }
    
    $headerToken = '';
    // Tenta buscar no array com chaves em caixa baixa
    if (!empty($lowercasedHeaders['x-csrf-token'])) {
        $headerToken = $lowercasedHeaders['x-csrf-token'];
    }
    
    // Fallback para variáveis de servidor do Apache (geradas automaticamente a partir do header original)
    if (empty($headerToken)) {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['http_x_csrf_token'] ?? '';
    }
    
    $bodyToken = $input['csrfToken'] ?? '';
    
    return hash_equals($sessionToken, $headerToken) || hash_equals($sessionToken, $bodyToken);
}

function handleCSRFTokenRoute() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    echo json_encode(["csrfToken" => $_SESSION['csrf_token']]);
}

function handleSessionRoute() {
    if (isset($_SESSION['user']) && !empty($_SESSION['user']['id'])) {
        echo json_encode([
            "authenticated" => true,
            "session" => [
                "role" => $_SESSION['user']['role'] ?? 'client',
                "id" => $_SESSION['user']['id'],
                "name" => $_SESSION['user']['name'] ?? '',
                "email" => $_SESSION['user']['email'] ?? '',
                "barbearia_id" => $_SESSION['user']['barbearia_id'] ?? 'default'
            ]
        ]);
    } else {
        echo json_encode([
            "authenticated" => false,
            "session" => null
        ]);
    }
}

// --------------------------------------------------------
// 5 - AUTENTICAÇÃO SEGURA, RATE LIMIT E MIGRAÇÃO DE SENHA
// --------------------------------------------------------
function handleLoginRoute($conn, $input) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        handleSessionRoute();
        return;
    }

    $password = $input['password'] ?? '';
    $phone = $input['phone'] ?? ($input['login'] ?? ($input['identifier'] ?? ''));
    $email = $input['email'] ?? ($input['login'] ?? ($input['identifier'] ?? ''));
    
    if (strpos($phone, '@') !== false) {
        $email = $phone;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    // Verificação de Força Bruta
    $stmt = $conn->prepare("SELECT attempts, blocked_until FROM login_attempts WHERE ip = ?");
    $stmt->bind_param("s", $ip);
    executeStmt($stmt);
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        if ($row['blocked_until'] && strtotime($row['blocked_until']) > time()) {
            $diff = strtotime($row['blocked_until']) - time();
            http_response_code(429);
            echo json_encode(["error" => "Muitas tentativas erradas. IP bloqueado. Tente em " . ceil($diff / 60) . " minutos."]);
            return;
        }
    }
    
    $cleanPhone = preg_replace('/\D/', '', $phone);
    $user = null;
    $authenticatedRole = null;
    
    // Ordem de verificação unificada: Admins/Gerentes -> Barbeiros -> Clientes
    $rolesToTry = ['admin', 'barber', 'client'];
    if (!empty($input['role']) && in_array($input['role'], ['admin', 'barber', 'client'], true)) {
        $rolesToTry = [$input['role']];
    }
    
    foreach ($rolesToTry as $currentRole) {
        $table = ($currentRole === 'admin') ? 'admins' : (($currentRole === 'barber') ? 'barbers' : 'clients');
        
        if (!empty($cleanPhone) && strlen($cleanPhone) >= 8) {
            $stmt = $conn->prepare("SELECT * FROM $table WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ? OR email = ?");
            $stmt->bind_param("ss", $cleanPhone, $phone);
        } else {
            $stmt = $conn->prepare("SELECT * FROM $table WHERE email = ?");
            $stmt->bind_param("s", $email);
        }
        
        executeStmt($stmt);
        $res = $stmt->get_result();
        $candidate = $res->fetch_assoc();
        
        if ($candidate) {
            $dbHash = $candidate['passwordHash'];
            if (is_string($dbHash) && password_verify($password, $dbHash)) {
                $user = $candidate;
                $authenticatedRole = $currentRole;
                break;
            }
        }
    }
    
    if ($user && $authenticatedRole) {
        // Limpa tentativas de login erradas
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?");
        $stmt->bind_param("s", $ip);
        executeStmt($stmt);
        
        $barbearia_id = $user['barbearia_id'] ?? 'default';
        session_regenerate_id(true);
        $effectiveRole = ($authenticatedRole === 'admin' && ($user['id'] ?? '') === 'admin-caixa') ? 'cashier' : $authenticatedRole;
        $_SESSION['user'] = [
            "id" => $user['id'],
            "role" => $effectiveRole,
            "name" => $user['name'],
            "email" => $user['email'],
            "barbearia_id" => $barbearia_id
        ];
        
        writeLog($conn, $user['id'], $barbearia_id, 'login', "Login realizado com sucesso ($effectiveRole) via IP: " . $ip);
        
        echo json_encode([
            "success" => true,
            "session" => [
                "role" => $effectiveRole,
                "id" => $user['id'],
                "name" => $user['name'],
                "email" => $user['email'],
                "barbearia_id" => $barbearia_id
            ]
        ]);
        return;
    }
    
    // Registra tentativa falha
    $stmt = $conn->prepare("SELECT attempts FROM login_attempts WHERE ip = ?");
    $stmt->bind_param("s", $ip);
    executeStmt($stmt);
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $newAttempts = $row['attempts'] + 1;
        if ($newAttempts >= 5) {
            $blockedUntil = date('Y-m-d H:i:s', time() + 15 * 60);
            $upd = $conn->prepare("UPDATE login_attempts SET attempts = ?, blocked_until = ? WHERE ip = ?");
            $upd->bind_param("iss", $newAttempts, $blockedUntil, $ip);
        } else {
            $upd = $conn->prepare("UPDATE login_attempts SET attempts = ? WHERE ip = ?");
            $upd->bind_param("is", $newAttempts, $ip);
        }
        executeStmt($upd);
    } else {
        $ins = $conn->prepare("INSERT INTO login_attempts (ip, attempts, blocked_until) VALUES (?, 1, NULL)");
        $ins->bind_param("s", $ip);
        executeStmt($ins);
    }
    
    writeLog($conn, null, 'default', 'login_failed', "Tentativa incorreta de login com identificador: " . ($phone ?: $email));
    
    http_response_code(401);
    echo json_encode(["error" => "Celular/e-mail ou senha incorretos."]);
}

function handleLogoutRoute($conn) {
    $userId = $_SESSION['user']['id'] ?? 'unknown';
    $tenantId = $_SESSION['user']['barbearia_id'] ?? 'default';
    writeLog($conn, $userId, $tenantId, 'logout', "Usuario efetuou logout");
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
    echo json_encode(["success" => true]);
}

// --------------------------------------------------------
// CONTROLADORES DE RECURSOS (PREPARED STATEMENTS & TENANCY)
// --------------------------------------------------------

// ADMS
function handleAdminsRoute($conn, $method, $tenantId) {
    if ($method === 'GET') {
        // Apenas administradores ou caixa podem listar administradores
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Acesso restrito."]);
            return;
        }
        $stmt = $conn->prepare("SELECT id, name, email, phone, barbearia_id FROM admins WHERE barbearia_id = ?");
        $stmt->bind_param("s", $tenantId);
        executeStmt($stmt);
        $result = $stmt->get_result();
        $admins = [];
        while ($row = $result->fetch_assoc()) {
            $admins[] = $row;
        }
        echo json_encode($admins);
    } else {
        http_response_code(405);
    }
}

// BARBEIROS
function handleBarbersRoute($conn, $method, $id, $input, $tenantId) {
    if ($method === 'GET') {
        $stmt = $conn->prepare("SELECT id, name, avatar, bio, email, phone, active, agendaActive, workDays, workStart, workEnd, lunchStart, lunchEnd, services, commissionPercentage, barbearia_id FROM barbers WHERE barbearia_id = ?");
        $stmt->bind_param("s", $tenantId);
        executeStmt($stmt);
        $result = $stmt->get_result();
        $barbers = [];
        while ($row = $result->fetch_assoc()) {
            $row['active'] = (bool)$row['active'];
            $row['agendaActive'] = (bool)$row['agendaActive'];
            $row['workDays'] = json_decode($row['workDays'], true) ?: [];
            $row['services'] = json_decode($row['services'], true) ?: [];
            $row['commissionPercentage'] = (int)$row['commissionPercentage'];
            $barbers[] = $row;
        }
        echo json_encode($barbers);
    } elseif ($method === 'POST') {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        
        // 20 - VALIDAÇÃO BACKEND
        if (empty($input['id']) || empty($input['name']) || empty($input['email']) || empty($input['password'])) {
            http_response_code(400);
            echo json_encode(["error" => "Campos obrigatorios ausentes."]);
            return;
        }
        
        // Segurança de senha: criptografa se informada
        $passHash = password_hash((string)$input['password'], PASSWORD_BCRYPT);
        
        $stmt = $conn->prepare("INSERT INTO barbers (id, name, avatar, bio, email, passwordHash, phone, active, agendaActive, workDays, workStart, workEnd, lunchStart, lunchEnd, services, commissionPercentage, barbearia_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $active = isset($input['active']) && $input['active'] ? 1 : 0;
        $agendaActive = isset($input['agendaActive']) && $input['agendaActive'] ? 1 : 0;
        $workDays = json_encode($input['workDays'] ?? []);
        $services = json_encode($input['services'] ?? []);
        $comm = (int)($input['commissionPercentage'] ?? 50);
        
        $stmt->bind_param("ssssssiissssssiss", 
            $input['id'], $input['name'], $input['avatar'], $input['bio'], 
            $input['email'], $passHash, $input['phone'], $active, 
            $agendaActive, $workDays, $input['workStart'], $input['workEnd'], 
            $input['lunchStart'], $input['lunchEnd'], $services, $comm, $tenantId
        );
        executeStmt($stmt);
        
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'barber_create', "Barbeiro cadastrado: " . $input['name']);
        echo json_encode(["success" => true]);
    } elseif ($method === 'PUT') {
        if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'barber'], true)) {
            http_response_code(403);
            echo json_encode(["error" => "Acesso restrito."]);
            return;
        }
        if ($_SESSION['user']['role'] === 'barber' && $_SESSION['user']['id'] !== $id) {
            http_response_code(403);
            echo json_encode(["error" => "Barbeiro nao pode editar outro perfil."]);
            return;
        }
        
        // Carrega o registro atual do barbeiro para mesclagem segura
        $stmt = $conn->prepare("SELECT * FROM barbers WHERE id = ? AND barbearia_id = ?");
        $stmt->bind_param("ss", $id, $tenantId);
        executeStmt($stmt);
        $curr = $stmt->get_result()->fetch_assoc();
        $passHash = $curr['passwordHash'] ?? '';
        
        if (!empty($input['password'])) {
            $passHash = password_hash((string)$input['password'], PASSWORD_BCRYPT);
        }
        
        $stmt = $conn->prepare("UPDATE barbers SET name=?, avatar=?, bio=?, email=?, passwordHash=?, phone=?, active=?, agendaActive=?, workDays=?, workStart=?, workEnd=?, lunchStart=?, lunchEnd=?, services=?, commissionPercentage=? WHERE id=? AND barbearia_id=?");
        $name = $input['name'] ?? ($curr['name'] ?? '');
        $avatar = array_key_exists('avatar', $input) ? $input['avatar'] : ($curr['avatar'] ?? null);
        $bio = $input['bio'] ?? ($curr['bio'] ?? '');
        $email = $input['email'] ?? ($curr['email'] ?? '');
        $phone = $input['phone'] ?? ($curr['phone'] ?? '');
        $active = isset($input['active']) ? ($input['active'] ? 1 : 0) : (int)($curr['active'] ?? 1);
        $agendaActive = isset($input['agendaActive']) ? ($input['agendaActive'] ? 1 : 0) : (int)($curr['agendaActive'] ?? 1);
        $workDays = json_encode($input['workDays'] ?? (json_decode($curr['workDays'] ?? '[]', true) ?: []));
        $workStart = $input['workStart'] ?? ($curr['workStart'] ?? '09:00');
        $workEnd = $input['workEnd'] ?? ($curr['workEnd'] ?? '19:00');
        $lunchStart = $input['lunchStart'] ?? ($curr['lunchStart'] ?? '12:00');
        $lunchEnd = $input['lunchEnd'] ?? ($curr['lunchEnd'] ?? '13:00');
        $services = json_encode($input['services'] ?? (json_decode($curr['services'] ?? '[]', true) ?: []));
        $comm = isset($input['commissionPercentage']) ? (int)$input['commissionPercentage'] : (int)($curr['commissionPercentage'] ?? 50);
        
        $stmt->bind_param("ssssssiissssssiss", 
            $name, $avatar, $bio, $email, 
            $passHash, $phone, $active, $agendaActive, 
            $workDays, $workStart, $workEnd, $lunchStart, 
            $lunchEnd, $services, $comm, $id, $tenantId
        );
        executeStmt($stmt);
        
        writeLog($conn, $_SESSION['user']['id'] ?? 'unknown', $tenantId, 'barber_update', "Barbeiro editado ID: " . $id);
        echo json_encode(["success" => true]);
    } elseif ($method === 'DELETE') {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        $stmt = $conn->prepare("DELETE FROM barbers WHERE id=? AND barbearia_id=?");
        $stmt->bind_param("ss", $id, $tenantId);
        executeStmt($stmt);
        
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'barber_delete', "Barbeiro removido ID: " . $id);
        echo json_encode(["success" => true]);
    }
}

// CLIENTES
function handleClientsRoute($conn, $method, $id, $input, $tenantId) {
    if ($method === 'GET') {
        if (!requireAuthenticated()) { return; }
        $role = $_SESSION['user']['role'] ?? '';
        $sessionUserId = $_SESSION['user']['id'] ?? '';
        if (!in_array($role, ['admin', 'cashier', 'barber', 'client'], true)) {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        // Filtro opcional de paginação para alta performance
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $offset = ($page - 1) * $limit;
        
        if ($role === 'client') {
            $stmt = $conn->prepare("SELECT id, name, email, phone, createdAt, barbearia_id, avatar FROM clients WHERE id = ? AND barbearia_id = ?");
            $stmt->bind_param("ss", $sessionUserId, $tenantId);
        } elseif ($limit > 0) {
            $stmt = $conn->prepare("SELECT id, name, email, phone, createdAt, barbearia_id, avatar FROM clients WHERE barbearia_id = ? LIMIT ? OFFSET ?");
            $stmt->bind_param("sii", $tenantId, $limit, $offset);
        } else {
            $stmt = $conn->prepare("SELECT id, name, email, phone, createdAt, barbearia_id, avatar FROM clients WHERE barbearia_id = ?");
            $stmt->bind_param("s", $tenantId);
        }
        
        executeStmt($stmt);
        $result = $stmt->get_result();
        $clients = [];
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
        echo json_encode($clients);
    } elseif ($method === 'POST') {
        // A API recebe apenas senha em texto durante a requisição de cadastro.
        // O hash nunca é recebido nem devolvido ao frontend.
        if (empty($input['id']) || empty($input['name']) || empty($input['phone'])) {
            http_response_code(400);
            echo json_encode(["error" => "Campos obrigatorios ausentes para cadastro de cliente."]);
            return;
        }

        $requestRole = $_SESSION['user']['role'] ?? '';
        $isInternalRegistration = in_array($requestRole, ['admin', 'cashier'], true);
        if (!$isInternalRegistration && empty($input['password'])) {
            http_response_code(400);
            echo json_encode(["error" => "Senha obrigatoria para cadastro do cliente."]);
            return;
        }

        // Cadastros internos sem senha recebem uma credencial aleatória não revelada.
        // Ela não é retornada ao painel e não permite que o atendente conheça a senha do cliente.
        $plainPassword = !empty($input['password'])
            ? (string)$input['password']
            : bin2hex(random_bytes(32));
        $passHash = password_hash($plainPassword, PASSWORD_BCRYPT);
        $createdAt = $input['createdAt'] ?? date('Y-m-d\TH:i:s\Z');
        
        $avatar = $input['avatar'] ?? null;
        $clientEmail = trim($input['email'] ?? '');
        $rawPhone = $input['phone'] ?? '';
        $cleanPhone = preg_replace('/\D/', '', $rawPhone);

        // 1. Verifica se o cliente já existe no sistema pelo telefone (ex: pré-cadastrado no PDV/balcão)
        $existingClient = null;
        if (!empty($cleanPhone)) {
            $checkPhoneStmt = $conn->prepare("SELECT id, name, email, barbearia_id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ? AND barbearia_id = ? LIMIT 1");
            if ($checkPhoneStmt) {
                $checkPhoneStmt->bind_param("ss", $cleanPhone, $tenantId);
                $checkPhoneStmt->execute();
                $resPhone = $checkPhoneStmt->get_result();
                if ($resPhone && $resPhone->num_rows > 0) {
                    $existingClient = $resPhone->fetch_assoc();
                }
            }
        }

        // Se o cliente já existia (ex: pelo PDV), atualiza a senha que ele acabou de criar e seus dados
        if ($existingClient) {
            $clientId = $existingClient['id'];
            $updateName = !empty($input['name']) ? $input['name'] : $existingClient['name'];
            $updateEmail = !empty($clientEmail) ? $clientEmail : $existingClient['email'];

            // Se alterou e-mail, confirma se não choca com outro cliente diferente
            if (!empty($clientEmail) && strtolower($clientEmail) !== strtolower($existingClient['email'])) {
                $checkStmt = $conn->prepare("SELECT id FROM clients WHERE email = ? AND barbearia_id = ? AND id != ? LIMIT 1");
                if ($checkStmt) {
                    $checkStmt->bind_param("sss", $clientEmail, $tenantId, $clientId);
                    $checkStmt->execute();
                    $checkRes = $checkStmt->get_result();
                    if ($checkRes && $checkRes->num_rows > 0) {
                        http_response_code(409);
                        echo json_encode(["error" => "Este e-mail já pertence a outra conta. Use outro e-mail."]);
                        return;
                    }
                }
            }

            $stmtUpdate = $conn->prepare("UPDATE clients SET name = ?, email = ?, passwordHash = ?, phone = ?, avatar = COALESCE(?, avatar) WHERE id = ? AND barbearia_id = ?");
            $stmtUpdate->bind_param("sssssss", $updateName, $updateEmail, $passHash, $rawPhone, $avatar, $clientId, $tenantId);
            executeStmt($stmtUpdate);

            writeLog($conn, $clientId, $tenantId, 'client_activate', "Cliente ativou/definiu sua senha pelo site: " . $updateName);
            echo json_encode(["success" => true, "id" => $clientId, "activated" => true]);
            return;
        }

        // 2. Novo cadastro: validação proativa de e-mail duplicado
        if (!empty($clientEmail)) {
            $checkStmt = $conn->prepare("SELECT id FROM clients WHERE email = ? AND barbearia_id = ? LIMIT 1");
            if ($checkStmt) {
                $checkStmt->bind_param("ss", $clientEmail, $tenantId);
                $checkStmt->execute();
                $checkRes = $checkStmt->get_result();
                if ($checkRes && $checkRes->num_rows > 0) {
                    http_response_code(409);
                    echo json_encode(["error" => "Este e-mail já está cadastrado. Por favor, faça login ou use outro e-mail."]);
                    return;
                }
            }
        }

        $stmt = $conn->prepare("INSERT INTO clients (id, name, email, phone, passwordHash, createdAt, barbearia_id, avatar) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssss", $input['id'], $input['name'], $clientEmail, $rawPhone, $passHash, $createdAt, $tenantId, $avatar);
        executeStmt($stmt);
        
        // Log automático
        $opId = $_SESSION['user']['id'] ?? $input['id'];
        writeLog($conn, $opId, $tenantId, 'client_create', "Novo cliente registrado: " . $input['name']);
        
        echo json_encode(["success" => true]);
    } elseif ($method === 'PUT') {
        if (!requireAuthenticated()) { return; }
        $role = $_SESSION['user']['role'] ?? '';
        if (!in_array($role, ['admin', 'cashier', 'client'], true) || ($role === 'client' && $_SESSION['user']['id'] !== $id)) {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada para editar este cliente."]);
            return;
        }
        
        // Carrega atual
        $stmt = $conn->prepare("SELECT passwordHash FROM clients WHERE id = ? AND barbearia_id = ?");
        $stmt->bind_param("ss", $id, $tenantId);
        executeStmt($stmt);
        $curr = $stmt->get_result()->fetch_assoc();
        $passHash = $curr['passwordHash'] ?? '';
        
        if (!empty($input['password'])) {
            $passHash = password_hash((string)$input['password'], PASSWORD_BCRYPT);
        }
        
        $avatar = $input['avatar'] ?? null;
        $stmt = $conn->prepare("UPDATE clients SET name=?, email=?, phone=?, passwordHash=?, avatar=? WHERE id=? AND barbearia_id=?");
        $stmt->bind_param("sssssss", $input['name'], $input['email'], $input['phone'], $passHash, $avatar, $id, $tenantId);
        executeStmt($stmt);
        
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'client_update', "Cliente editado ID: " . $id);
        echo json_encode(["success" => true]);
    }
}

// AGENDAMENTOS
function handleBookingsRoute($conn, $method, $id, $input, $tenantId) {
    if ($method === 'GET') {
        // 9 - PERMISSÕES: Perfil vê somente o necessário
        $role = $_SESSION['user']['role'] ?? '';
        $userId = $_SESSION['user']['id'] ?? '';
        
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $offset = ($page - 1) * $limit;
        
        if ($role === 'client') {
            $stmtStr = "SELECT * FROM bookings WHERE clientId = ? AND barbearia_id = ?";
            if ($limit > 0) { $stmtStr .= " LIMIT ? OFFSET ?"; }
            $stmt = $conn->prepare($stmtStr);
            if ($limit > 0) { $stmt->bind_param("ssii", $userId, $tenantId, $limit, $offset); }
            else { $stmt->bind_param("ss", $userId, $tenantId); }
        } elseif ($role === 'barber') {
            $stmtStr = "SELECT * FROM bookings WHERE barberId = ? AND barbearia_id = ?";
            if ($limit > 0) { $stmtStr .= " LIMIT ? OFFSET ?"; }
            $stmt = $conn->prepare($stmtStr);
            if ($limit > 0) { $stmt->bind_param("ssii", $userId, $tenantId, $limit, $offset); }
            else { $stmt->bind_param("ss", $userId, $tenantId); }
        } elseif (in_array($role, ['admin', 'cashier'], true)) {
            $stmtStr = "SELECT * FROM bookings WHERE barbearia_id = ?";
            if ($limit > 0) { $stmtStr .= " LIMIT ? OFFSET ?"; }
            $stmt = $conn->prepare($stmtStr);
            if ($limit > 0) { $stmt->bind_param("sii", $tenantId, $limit, $offset); }
            else { $stmt->bind_param("s", $tenantId); }
        } else {
            http_response_code(401);
            echo json_encode(["error" => "Autenticacao obrigatoria."]);
            return;
        }
        
        executeStmt($stmt);
        $result = $stmt->get_result();
        $bookings = [];
        while ($row = $result->fetch_assoc()) {
            $row['price'] = (float)$row['price'];
            $row['discount'] = (float)$row['discount'];
            $row['cashierProcessed'] = (bool)$row['cashierProcessed'];
            $row['extraServices'] = json_decode($row['extraServices'], true) ?: [];
            $row['extraProducts'] = json_decode($row['extraProducts'], true) ?: [];
            $bookings[] = $row;
        }
        echo json_encode($bookings);
    } elseif ($method === 'POST') {
        if (!requireAuthenticated()) { return; }
        $role = $_SESSION['user']['role'] ?? '';
        if ($role === 'client' && (string)($input['clientId'] ?? '') !== (string)$_SESSION['user']['id']) {
            http_response_code(403);
            echo json_encode(["error" => "Cliente nao pode criar agendamento para outro usuario."]);
            return;
        }
        if (!in_array($role, ['admin', 'cashier', 'client'], true)) {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada para criar agendamento."]);
            return;
        }
        // 10 - AGENDAMENTOS: VALIDAR REGRAS NO BACKEND
        $valError = validateBookingRule($conn, $input, null, $tenantId);
        if ($valError) {
            http_response_code(400);
            echo json_encode(["error" => $valError]);
            return;
        }
        
        $stmt = $conn->prepare("INSERT INTO bookings (id, barberId, barberName, clientId, clientName, clientPhone, serviceId, serviceName, price, date, time, paymentMethod, paymentStatus, status, extraServices, extraProducts, discount, paymentForm, notes, cashierProcessed, barbearia_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            error_log("Erro de preparação SQL no INSERT INTO bookings: " . $conn->error);
            http_response_code(500);
            echo json_encode(["error" => "Erro ao preparar o agendamento."]);
            return;
        }

        $idVal = (string)($input['id'] ?? '');
        $barberIdVal = (string)($input['barberId'] ?? '');
        $barberNameVal = (string)($input['barberName'] ?? '');
        $clientIdVal = (string)($input['clientId'] ?? '');
        $clientNameVal = (string)($input['clientName'] ?? '');
        $clientPhoneVal = (string)($input['clientPhone'] ?? '');
        $serviceIdVal = (string)($input['serviceId'] ?? '');
        $serviceNameVal = (string)($input['serviceName'] ?? '');
        $priceVal = (float)($input['price'] ?? 0.00);
        $dateVal = (string)($input['date'] ?? '');
        $timeVal = (string)($input['time'] ?? '');
        $paymentMethodVal = (string)($input['paymentMethod'] ?? 'local');
        $paymentStatusVal = (string)($input['paymentStatus'] ?? 'pending');
        $statusVal = (string)($input['status'] ?? 'pending');
        $extraServices = json_encode(isset($input['extraServices']) && is_array($input['extraServices']) ? $input['extraServices'] : []);
        $extraProducts = json_encode(isset($input['extraProducts']) && is_array($input['extraProducts']) ? $input['extraProducts'] : []);
        $discountVal = (float)($input['discount'] ?? 0.00);
        $paymentFormVal = (string)($input['paymentForm'] ?? '');
        $notesVal = (string)($input['notes'] ?? '');
        $cashierProcessedVal = (isset($input['cashierProcessed']) && $input['cashierProcessed']) ? 1 : 0;

        if ($paymentStatusVal === 'Pago Online') {
            $gatewayPaymentId = trim((string)($input['gatewayPaymentId'] ?? ''));
            if ($gatewayPaymentId === '') {
                http_response_code(402);
                echo json_encode(["error" => "Pagamento online não confirmado pelo gateway."]);
                return;
            }
            $payCheck = $conn->prepare("SELECT id, total, gateway_provider, gateway_status, used_at FROM payment_history WHERE booking_id = ? AND gateway_payment_id = ? AND barbearia_id = ? ORDER BY id DESC LIMIT 1");
            $payCheck->bind_param("sss", $idVal, $gatewayPaymentId, $tenantId);
            executeStmt($payCheck);
            $payRow = $payCheck->get_result()->fetch_assoc();
            if (!$payRow || !isGatewayProvider($payRow['gateway_provider']) || !isApprovedGatewayStatus($payRow['gateway_provider'], $payRow['gateway_status']) || !empty($payRow['used_at']) || abs((float)$payRow['total'] - $priceVal) > 0.009) {
                http_response_code(402);
                echo json_encode(["error" => "Pagamento online não confirmado, já utilizado ou com valor divergente."]);
                return;
            }
        }
        
        $stmt->bind_param("ssssssssdsssssssdssis", 
            $idVal, $barberIdVal, $barberNameVal, $clientIdVal,
            $clientNameVal, $clientPhoneVal, $serviceIdVal, $serviceNameVal,
            $priceVal, $dateVal, $timeVal, $paymentMethodVal,
            $paymentStatusVal, $statusVal, $extraServices, $extraProducts,
            $discountVal, $paymentFormVal, $notesVal, $cashierProcessedVal, $tenantId
        );
        
        if (!$stmt->execute()) {
            error_log("Erro ao salvar agendamento: " . $stmt->error);
            if ($conn->errno === 1062) {
                http_response_code(409);
                echo json_encode(["error" => "Agendamento já cadastrado ou ID duplicado."]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Falha ao salvar agendamento."]);
            }
            $stmt->close();
            return;
        }
        $stmt->close();

        if ($paymentStatusVal === 'Pago Online' && !empty($gatewayPaymentId)) {
            $used = $conn->prepare("UPDATE payment_history SET used_at = NOW() WHERE booking_id = ? AND gateway_payment_id = ? AND barbearia_id = ? AND used_at IS NULL");
            $used->bind_param("sss", $idVal, $gatewayPaymentId, $tenantId);
            executeStmt($used);
        }
        
        writeLog($conn, $_SESSION['user']['id'] ?? $clientIdVal, $tenantId, 'booking_create', "Novo agendamento criado: " . $serviceNameVal . " com " . $barberNameVal . " em " . $dateVal);
        echo json_encode(["success" => true]);
    } elseif ($method === 'PUT') {
        if (!requireAuthenticated()) { return; }
        $role = $_SESSION['user']['role'] ?? '';
        if (!in_array($role, ['admin', 'cashier', 'barber'], true)) {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada para atualizar agendamento."]);
            return;
        }
        if ($role === 'barber') {
            $own = $conn->prepare("SELECT id FROM bookings WHERE id = ? AND barberId = ? AND barbearia_id = ?");
            $own->bind_param("sss", $id, $_SESSION['user']['id'], $tenantId);
            executeStmt($own);
            if (!$own->get_result()->fetch_assoc()) {
                http_response_code(403);
                echo json_encode(["error" => "Barbeiro nao pode alterar agendamento de outro profissional."]);
                return;
            }
        }
        // Carrega o agendamento atual antes de decidir se as regras de agenda precisam ser revalidadas.
        // Alterações administrativas (status, pagamento, caixa, observações etc.) não são uma remarcação.
        $stmt = $conn->prepare("SELECT barberId, date, time, price, paymentStatus FROM bookings WHERE id = ? AND barbearia_id = ?");
        $stmt->bind_param("ss", $id, $tenantId);
        executeStmt($stmt);
        $curr = $stmt->get_result()->fetch_assoc();

        if (!$curr) {
            http_response_code(404);
            echo json_encode(["error" => "Agendamento nao encontrado."]);
            return;
        }

        $scheduleChanged =
            (string)($input['barberId'] ?? '') !== (string)$curr['barberId'] ||
            (string)($input['date'] ?? '') !== (string)$curr['date'] ||
            (string)($input['time'] ?? '') !== (string)$curr['time'];

        // Revalida as regras de agenda somente quando houve remarcação real.
        // Finalizar atendimento no caixa não deve falhar apenas porque o horário original já passou.
        if ($scheduleChanged) {
            $valError = validateBookingRule($conn, $input, $id, $tenantId);
            if ($valError) {
                http_response_code(400);
                echo json_encode(["error" => $valError]);
                return;
            }
        }

        // 18 - PAGAMENTOS: HISTÓRICO DE ALTERAÇÃO DE PAGAMENTO E PREÇO
        logPaymentChange(
            $conn,
            $id,
            $curr['price'],
            $input['price'],
            $curr['paymentStatus'],
            $input['paymentStatus']
        );

        $stmt = $conn->prepare("UPDATE bookings SET barberId=?, barberName=?, clientId=?, clientName=?, clientPhone=?, serviceId=?, serviceName=?, price=?, date=?, time=?, paymentMethod=?, paymentStatus=?, status=?, extraServices=?, extraProducts=?, discount=?, paymentForm=?, notes=?, cashierProcessed=? WHERE id=? AND barbearia_id=?");
        if (!$stmt) {
            error_log("Erro de preparação SQL no UPDATE bookings: " . $conn->error);
            http_response_code(500);
            echo json_encode(["error" => "Erro ao atualizar o agendamento."]);
            return;
        }

        $barberIdVal = (string)($input['barberId'] ?? '');
        $barberNameVal = (string)($input['barberName'] ?? '');
        $clientIdVal = (string)($input['clientId'] ?? '');
        $clientNameVal = (string)($input['clientName'] ?? '');
        $clientPhoneVal = (string)($input['clientPhone'] ?? '');
        $serviceIdVal = (string)($input['serviceId'] ?? '');
        $serviceNameVal = (string)($input['serviceName'] ?? '');
        $priceVal = (float)($input['price'] ?? 0.00);
        $dateVal = (string)($input['date'] ?? '');
        $timeVal = (string)($input['time'] ?? '');
        $paymentMethodVal = (string)($input['paymentMethod'] ?? 'local');
        $paymentStatusVal = (string)($input['paymentStatus'] ?? 'pending');
        $statusVal = (string)($input['status'] ?? 'pending');
        $extraServices = json_encode(isset($input['extraServices']) && is_array($input['extraServices']) ? $input['extraServices'] : []);
        $extraProducts = json_encode(isset($input['extraProducts']) && is_array($input['extraProducts']) ? $input['extraProducts'] : []);
        $discountVal = (float)($input['discount'] ?? 0.00);
        $paymentFormVal = (string)($input['paymentForm'] ?? '');
        $notesVal = (string)($input['notes'] ?? '');
        $cashierProcessedVal = (isset($input['cashierProcessed']) && $input['cashierProcessed']) ? 1 : 0;

        // Segurança: um UPDATE comum não pode transformar pagamento pendente em Pago Online.
        if (($curr['paymentStatus'] ?? '') !== 'Pago Online' && $paymentStatusVal === 'Pago Online') {
            $gatewayPaymentId = trim((string)($input['gatewayPaymentId'] ?? ''));
            if ($gatewayPaymentId === '') {
                http_response_code(409);
                echo json_encode(["error" => "Pagamento online sem confirmação real do gateway."]);
                return;
            }

            $payCheck = $conn->prepare("SELECT gateway_provider, gateway_status, total FROM payment_history WHERE booking_id = ? AND gateway_payment_id = ? AND barbearia_id = ? ORDER BY id DESC LIMIT 1");
            $payCheck->bind_param("sss", $id, $gatewayPaymentId, $tenantId);
            executeStmt($payCheck);
            $payRow = $payCheck->get_result()->fetch_assoc();
            $payCheck->close();

            if (!$payRow || !isGatewayProvider($payRow['gateway_provider']) || !isApprovedGatewayStatus($payRow['gateway_provider'], $payRow['gateway_status']) || abs((float)$payRow['total'] - $priceVal) > 0.009) {
                http_response_code(409);
                echo json_encode(["error" => "Pagamento online não confirmado pelo gateway."]);
                return;
            }
        }
        
        $stmt->bind_param("sssssssdsssssssdssiss", 
            $barberIdVal, $barberNameVal, $clientIdVal, $clientNameVal,
            $clientPhoneVal, $serviceIdVal, $serviceNameVal, $priceVal,
            $dateVal, $timeVal, $paymentMethodVal, $paymentStatusVal,
            $statusVal, $extraServices, $extraProducts, $discountVal,
            $paymentFormVal, $notesVal, $cashierProcessedVal, $id, $tenantId
        );
        
        if (!$stmt->execute()) {
            $updateError = $stmt->error ?: $conn->error;
            error_log("Erro ao atualizar agendamento: " . $updateError);
            http_response_code(500);
            echo json_encode([
                "error" => "Falha ao atualizar agendamento.",
                "message" => $updateError
            ]);
            $stmt->close();
            return;
        }
        $stmt->close();
        
        writeLog($conn, $_SESSION['user']['id'] ?? 'unknown', $tenantId, 'booking_update', "Agendamento atualizado ID: " . $id . " Status: " . $input['status']);
        echo json_encode(["success" => true]);
    } elseif ($method === 'DELETE') {
        if (!requireRoles(['admin', 'cashier'])) { return; }
        $stmt = $conn->prepare("DELETE FROM bookings WHERE id=? AND barbearia_id=?");
        $stmt->bind_param("ss", $id, $tenantId);
        executeStmt($stmt);
        
        writeLog($conn, $_SESSION['user']['id'] ?? 'unknown', $tenantId, 'booking_delete', "Agendamento deletado ID: " . $id);
        echo json_encode(["success" => true]);
    }
}

// PRODUTOS
function handleProductsRoute($conn, $method, $id, $input, $tenantId) {
    if ($method === 'GET') {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $offset = ($page - 1) * $limit;
        
        if ($limit > 0) {
            $stmt = $conn->prepare("SELECT * FROM products WHERE barbearia_id = ? LIMIT ? OFFSET ?");
            $stmt->bind_param("sii", $tenantId, $limit, $offset);
        } else {
            $stmt = $conn->prepare("SELECT * FROM products WHERE barbearia_id = ?");
            $stmt->bind_param("s", $tenantId);
        }
        
        executeStmt($stmt);
        $result = $stmt->get_result();
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $row['price'] = (float)$row['price'];
            $row['stock'] = (int)$row['stock'];
            $products[] = $row;
        }
        echo json_encode($products);
    } elseif ($method === 'POST') {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        
        $stmt = $conn->prepare("INSERT INTO products (id, image, name, description, price, category, stock, barbearia_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stock = (int)($input['stock'] ?? 0);
        $stmt->bind_param("ssssdsis", $input['id'], $input['image'], $input['name'], $input['description'], $input['price'], $input['category'], $stock, $tenantId);
        executeStmt($stmt);
        
        // 11 - ESTOQUE: HISTÓRICO INICIAL
        logStockChange($conn, $input['id'], 0, $stock);
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'product_create', "Novo produto cadastrado: " . $input['name'] . " Estoque: " . $stock);
        
        echo json_encode(["success" => true]);
    } elseif ($method === 'PUT') {
        if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'cashier'])) {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        
        // 11 - ESTOQUE: REGISTRAR ALTERAÇÕES NO HISTÓRICO
        $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ? AND barbearia_id = ?");
        $stmt->bind_param("ss", $id, $tenantId);
        executeStmt($stmt);
        $curr = $stmt->get_result()->fetch_assoc();
        $oldStock = $curr ? (int)$curr['stock'] : 0;
        $newStock = (int)$input['stock'];
        
        if ($oldStock !== $newStock) {
            logStockChange($conn, $id, $oldStock, $newStock);
        }
        
        $stmt = $conn->prepare("UPDATE products SET image=?, name=?, description=?, price=?, category=?, stock=? WHERE id=? AND barbearia_id=?");
        $stmt->bind_param("sssdsiss", $input['image'], $input['name'], $input['description'], $input['price'], $input['category'], $newStock, $id, $tenantId);
        executeStmt($stmt);
        
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'product_update', "Produto editado ID: " . $id . " Novo estoque: " . $newStock);
        echo json_encode(["success" => true]);
    } elseif ($method === 'DELETE') {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        $stmt = $conn->prepare("DELETE FROM products WHERE id=? AND barbearia_id=?");
        $stmt->bind_param("ss", $id, $tenantId);
        executeStmt($stmt);
        
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'product_delete', "Produto removido ID: " . $id);
        echo json_encode(["success" => true]);
    }
}

// BEBIDAS (DRINKS)
function handleDrinksRoute($conn, $method, $id, $input, $tenantId) {
    if ($method === 'GET') {
        $stmt = $conn->prepare("SELECT * FROM drinks WHERE barbearia_id = ?");
        $stmt->bind_param("s", $tenantId);
        executeStmt($stmt);
        $result = $stmt->get_result();
        $drinks = [];
        while ($row = $result->fetch_assoc()) {
            $row['price'] = (float)$row['price'];
            $drinks[] = $row;
        }
        echo json_encode($drinks);
    } elseif ($method === 'POST') {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        $stmt = $conn->prepare("INSERT INTO drinks (id, image, name, description, price, barbearia_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssds", $input['id'], $input['image'], $input['name'], $input['description'], $input['price'], $tenantId);
        executeStmt($stmt);
        
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'drink_create', "Bebida cadastrada: " . $input['name']);
        echo json_encode(["success" => true]);
    } elseif ($method === 'PUT') {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        $stmt = $conn->prepare("UPDATE drinks SET image=?, name=?, description=?, price=? WHERE id=? AND barbearia_id=?");
        $stmt->bind_param("sssdss", $input['image'], $input['name'], $input['description'], $input['price'], $id, $tenantId);
        executeStmt($stmt);
        
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'drink_update', "Bebida editada ID: " . $id);
        echo json_encode(["success" => true]);
    } elseif ($method === 'DELETE') {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        $stmt = $conn->prepare("DELETE FROM drinks WHERE id=? AND barbearia_id=?");
        $stmt->bind_param("ss", $id, $tenantId);
        executeStmt($stmt);
        
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'drink_delete', "Bebida deletada ID: " . $id);
        echo json_encode(["success" => true]);
    }
}

// PEDIDOS (ORDERS)
function handleOrdersRoute($conn, $method, $input, $tenantId) {
    if ($method === 'GET') {
        $role = $_SESSION['user']['role'] ?? '';
        $userId = $_SESSION['user']['id'] ?? '';
        
        if ($role === 'client') {
            $stmt = $conn->prepare("SELECT * FROM orders WHERE clientId = ? AND barbearia_id = ?");
            $stmt->bind_param("ss", $userId, $tenantId);
        } elseif (in_array($role, ['admin', 'cashier'], true)) {
            $stmt = $conn->prepare("SELECT * FROM orders WHERE barbearia_id = ?");
            $stmt->bind_param("s", $tenantId);
        } else {
            http_response_code(401);
            echo json_encode(["error" => "Autenticacao obrigatoria."]);
            return;
        }
        
        executeStmt($stmt);
        $result = $stmt->get_result();
        $orders = [];
        while ($row = $result->fetch_assoc()) {
            $row['total'] = (float)$row['total'];
            $row['items'] = json_decode($row['items'], true) ?: [];
            $orders[] = $row;
        }
        echo json_encode($orders);
    } elseif ($method === 'POST') {
        if (!requireAuthenticated()) { return; }
        $role = $_SESSION['user']['role'] ?? '';
        if ($role === 'client' && (string)($input['clientId'] ?? '') !== (string)$_SESSION['user']['id']) {
            http_response_code(403);
            echo json_encode(["error" => "Cliente nao pode criar pedido para outro usuario."]);
            return;
        }
        if (!in_array($role, ['admin', 'cashier', 'client'], true)) {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada para criar pedido."]);
            return;
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO orders (id, clientId, clientName, items, total, date, status, barbearia_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $items = json_encode($input['items']);
            $stmt->bind_param("ssssdsss", $input['id'], $input['clientId'], $input['clientName'], $items, $input['total'], $input['date'], $input['status'], $tenantId);
            executeStmt($stmt);
            
            // 11 - ESTOQUE: Dedução de estoque e registro de histórico
            foreach ($input['items'] as $item) {
                // Seleciona estoque atual
                $g = $conn->prepare("SELECT name, stock FROM products WHERE id = ? AND barbearia_id = ?");
                $g->bind_param("ss", $item['productId'], $tenantId);
                executeStmt($g);
                $prod = $g->get_result()->fetch_assoc();
                
                if ($prod) {
                    $oldStock = (int)$prod['stock'];
                    $qtySold = (int)$item['quantity'];
                    $newStock = max(0, $oldStock - $qtySold);
                    
                    $upd = $conn->prepare("UPDATE products SET stock = ? WHERE id = ? AND barbearia_id = ?");
                    $upd->bind_param("iss", $newStock, $item['productId'], $tenantId);
                    executeStmt($upd);
                    
                    logStockChange($conn, $item['productId'], $oldStock, $newStock);
                }
            }
            
            $conn->commit();
            
            $opId = $_SESSION['user']['id'] ?? $input['clientId'];
            writeLog($conn, $opId, $tenantId, 'order_create', "Pedido criado ID: " . $input['id'] . " Total: R$ " . $input['total']);
            
            echo json_encode(["success" => true]);
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

// PAGAMENTOS CONFIG
function handlePaymentSettingsRoute($conn, $method, $input, $tenantId) {
    global $defaultPayment;
    if ($method === 'GET') {
        $stmt = $conn->prepare("SELECT * FROM payment_settings WHERE id = 'default' AND barbearia_id = ?");
        $stmt->bind_param("s", $tenantId);
        executeStmt($stmt);
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $row['gatewayProvider'] = normalizeGatewayProvider($row['gatewayProvider'] ?? '') ?: 'manual';
            $row['gatewayConfigured'] = isGatewayProvider($row['gatewayProvider']) && !empty($row['gatewayToken']);
            $row['gatewayToken'] = '';
            echo json_encode($row);
        } else {
            $safeDefault = $defaultPayment;
            $safeDefault['gatewayConfigured'] = false;
            $safeDefault['gatewayToken'] = '';
            echo json_encode($safeDefault);
        }
    } elseif ($method === 'PUT') {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        
        $gatewayProvider = normalizeGatewayProvider($input['gatewayProvider'] ?? 'manual');
        if ($gatewayProvider === '') {
            http_response_code(400);
            echo json_encode(["error" => "Provedor inválido. Valores aceitos: asaas, mercado_pago ou manual."]);
            return;
        }
        $gatewayToken = isset($input['gatewayToken']) && trim((string)$input['gatewayToken']) !== '' ? trim((string)$input['gatewayToken']) : null;
        $stmt = $conn->prepare("INSERT INTO payment_settings (id, pixKey, pixKeyType, beneficiaryName, bankName, city, gatewayToken, gatewayProvider, barbearia_id) VALUES ('default', ?, ?, ?, ?, ?, COALESCE(?, ''), ?, ?) ON DUPLICATE KEY UPDATE pixKey=?, pixKeyType=?, beneficiaryName=?, bankName=?, city=?, gatewayToken=COALESCE(?, gatewayToken), gatewayProvider=?");
        $stmt->bind_param("ssssssssssssssss", 
            $input['pixKey'], $input['pixKeyType'], $input['beneficiaryName'], $input['bankName'], $input['city'], $gatewayToken, $gatewayProvider, $tenantId,
            $input['pixKey'], $input['pixKeyType'], $input['beneficiaryName'], $input['bankName'], $input['city'], $gatewayToken, $gatewayProvider
        );
        executeStmt($stmt);
        
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'payment_settings_update', "Configurações de PIX/Pagamento atualizadas");
        echo json_encode(["success" => true]);
    }
}

// PLANOS DE ASSINATURA
function handleSubscriptionPlansRoute($conn, $method, $id, $input, $tenantId) {
    if ($method === 'GET') {
        $stmt = $conn->prepare("SELECT * FROM subscription_plans WHERE barbearia_id = ?");
        $stmt->bind_param("s", $tenantId);
        executeStmt($stmt);
        $res = $stmt->get_result();
        $plans = [];
        while ($row = $res->fetch_assoc()) {
            $row['price'] = (float)$row['price'];
            $row['maxCutsPerMonth'] = (int)$row['maxCutsPerMonth'];
            $row['maxBeardsPerMonth'] = (int)$row['maxBeardsPerMonth'];
            $row['active'] = (bool)$row['active'];
            $row['features'] = json_decode($row['features'], true) ?: [];
            $plans[] = $row;
        }
        echo json_encode($plans);
    } elseif ($method === 'POST') {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        $features = isset($input['features']) && is_array($input['features']) ? json_encode($input['features']) : '[]';
        $active = isset($input['active']) ? ($input['active'] ? 1 : 0) : 1;
        $badge = isset($input['badge']) ? $input['badge'] : '';
        $maxCuts = isset($input['maxCutsPerMonth']) ? (int)$input['maxCutsPerMonth'] : 0;
        $maxBeards = isset($input['maxBeardsPerMonth']) ? (int)$input['maxBeardsPerMonth'] : 0;
        
        $stmt = $conn->prepare("INSERT INTO subscription_plans (id, name, price, cycle, description, features, maxCutsPerMonth, maxBeardsPerMonth, badge, active, barbearia_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdsssiisis",
            $input['id'], $input['name'], $input['price'], $input['cycle'], $input['description'],
            $features, $maxCuts, $maxBeards, $badge, $active, $tenantId
        );
        executeStmt($stmt);
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'plan_create', "Plano de assinatura criado: " . $input['name']);
        echo json_encode(["success" => true]);
    } elseif ($method === 'PUT' && $id) {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        $features = isset($input['features']) && is_array($input['features']) ? json_encode($input['features']) : '[]';
        $active = isset($input['active']) ? ($input['active'] ? 1 : 0) : 1;
        $badge = isset($input['badge']) ? $input['badge'] : '';
        $maxCuts = isset($input['maxCutsPerMonth']) ? (int)$input['maxCutsPerMonth'] : 0;
        $maxBeards = isset($input['maxBeardsPerMonth']) ? (int)$input['maxBeardsPerMonth'] : 0;
        
        $stmt = $conn->prepare("UPDATE subscription_plans SET name=?, price=?, cycle=?, description=?, features=?, maxCutsPerMonth=?, maxBeardsPerMonth=?, badge=?, active=? WHERE id=? AND barbearia_id=?");
        $stmt->bind_param("sdsssiisis",
            $input['name'], $input['price'], $input['cycle'], $input['description'],
            $features, $maxCuts, $maxBeards, $badge, $active, $id, $tenantId
        );
        executeStmt($stmt);
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'plan_update', "Plano de assinatura atualizado: " . $input['name']);
        echo json_encode(["success" => true]);
    } elseif ($method === 'DELETE' && $id) {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        $stmt = $conn->prepare("DELETE FROM subscription_plans WHERE id=? AND barbearia_id=?");
        $stmt->bind_param("ss", $id, $tenantId);
        executeStmt($stmt);
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'plan_delete', "Plano de assinatura removido ID: " . $id);
        echo json_encode(["success" => true]);
    }
}

// CATALOGO DE SERVICOS (INICIO)
function handleServiceCatalogRoute($conn, $method, $id, $input, $tenantId) {
    if ($method === 'GET') {
        $stmt = $conn->prepare("SELECT * FROM service_catalog WHERE barbearia_id = ?");
        $stmt->bind_param("s", $tenantId);
        executeStmt($stmt);
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $row['popular'] = (bool)$row['popular'];
            $items[] = $row;
        }
        echo json_encode($items);
    } elseif ($method === 'POST') {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        $popular = isset($input['popular']) && $input['popular'] ? 1 : 0;
        $desc = $input['description'] ?? '';
        $stmt = $conn->prepare("INSERT INTO service_catalog (id, category, name, description, duration, price, popular, barbearia_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssis",
            $input['id'], $input['category'], $input['name'], $desc,
            $input['duration'], $input['price'], $popular, $tenantId
        );
        executeStmt($stmt);
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'catalog_create', "Servico do catalogo criado: " . $input['name']);
        echo json_encode(["success" => true]);
    } elseif ($method === 'PUT' && $id) {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        $popular = isset($input['popular']) && $input['popular'] ? 1 : 0;
        $desc = $input['description'] ?? '';
        $stmt = $conn->prepare("UPDATE service_catalog SET category=?, name=?, description=?, duration=?, price=?, popular=? WHERE id=? AND barbearia_id=?");
        $stmt->bind_param("sssssiss",
            $input['category'], $input['name'], $desc,
            $input['duration'], $input['price'], $popular, $id, $tenantId
        );
        executeStmt($stmt);
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'catalog_update', "Servico do catalogo atualizado: " . $input['name']);
        echo json_encode(["success" => true]);
    } elseif ($method === 'DELETE' && $id) {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }
        $stmt = $conn->prepare("DELETE FROM service_catalog WHERE id=? AND barbearia_id=?");
        $stmt->bind_param("ss", $id, $tenantId);
        executeStmt($stmt);
        writeLog($conn, $_SESSION['user']['id'], $tenantId, 'catalog_delete', "Servico do catalogo removido ID: " . $id);
        echo json_encode(["success" => true]);
    }
}

// Registra imediatamente a cobrança real criada no gateway. O registro permanece
// pendente até uma consulta real ao provedor confirmar o recebimento.
function registerGatewayPayment($conn, $referenceId, $paymentId, $provider, $gatewayStatus, $amount, $originalAmount, $discount, $tenantId) {
    $provider = normalizeGatewayProvider($provider);
    if (!isGatewayProvider($provider)) {
        throw new Exception('Provedor de gateway inválido para payment_history.');
    }
    $userId = (string)($_SESSION['user']['id'] ?? 'unknown');
    $userName = (string)($_SESSION['user']['name'] ?? 'Usuário');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $date = date('Y-m-d');
    $time = date('H:i');
    $statusOld = '';
    $statusNew = 'pending';
    $currency = 'BRL';
    $errorMessage = null;
    $stmt = $conn->prepare("INSERT INTO payment_history (booking_id, usuario_id, user_name, ip, date, time, price_old, price_new, status_old, status_new, gateway_payment_id, gateway_provider, gateway_status, currency, original_amount, discount, total, error_message, barbearia_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) { throw new Exception('Falha ao preparar registro do pagamento.'); }
    $stmt->bind_param("ssssssdssssssdddss", $referenceId, $userId, $userName, $ip, $date, $time, $amount, $statusOld, $statusNew, $paymentId, $provider, $gatewayStatus, $currency, $originalAmount, $discount, $amount, $errorMessage, $tenantId);
    if (!$stmt->execute()) {
        if ($conn->errno === 1062) { throw new Exception('Pagamento duplicado detectado.'); }
        throw new Exception('Falha ao registrar o pagamento antes da confirmação.');
    }
    $stmt->close();
}

// ROTAS DE PAGAMENTO (ASAAS, MERCADO PAGO, MANUAL)
function handlePaymentsRoute($conn, $method, $segments, $input, $tenantId) {
    global $defaultPayment;
    $action = $segments[1] ?? '';
    $idParam = $segments[2] ?? '';
    
    // Suporte para /api/payments/:id/status ou /api/payments/status/:id
    if ($action === 'status' && !empty($idParam)) {
        $paymentId = $idParam;
        $action = 'check_status';
    } elseif ($idParam === 'status' && !empty($action)) {
        $paymentId = $action;
        $action = 'check_status';
    }

    $stmt = $conn->prepare("SELECT * FROM payment_settings WHERE id = 'default' AND barbearia_id = ?");
    $stmt->bind_param("s", $tenantId);
    executeStmt($stmt);
    $res = $stmt->get_result();
    $settings = $res->fetch_assoc() ?: $defaultPayment;
    
    if (!requireAuthenticated()) { return; }

    if ($action === 'test-asaas' && $method === 'POST') {
        if (!requireRoles(['admin'])) { return; }
        $token = trim($input['token'] ?? '');
        if (empty($token)) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Informe a Chave de API do Asaas."]);
            return;
        }
        
        $ch = curl_init("https://www.asaas.com/api/v3/myAccount");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["access_token: " . $token]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true) ?: [];
            echo json_encode([
                "success" => true,
                "environment" => "Produção (www.asaas.com)",
                "companyName" => $data['companyName'] ?? $data['name'] ?? "Conta Asaas",
                "email" => $data['email'] ?? ""
            ]);
            return;
        }
        
        $chSand = curl_init("https://sandbox.asaas.com/api/v3/myAccount");
        curl_setopt($chSand, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chSand, CURLOPT_HTTPHEADER, ["access_token: " . $token]);
        $responseSand = curl_exec($chSand);
        $httpCodeSand = curl_getinfo($chSand, CURLINFO_HTTP_CODE);
        curl_close($chSand);
        
        if ($httpCodeSand === 200) {
            $data = json_decode($responseSand, true) ?: [];
            echo json_encode([
                "success" => true,
                "environment" => "Sandbox / Ambiente de Testes",
                "companyName" => $data['companyName'] ?? $data['name'] ?? "Conta Sandbox Asaas",
                "email" => $data['email'] ?? ""
            ]);
            return;
        }
        
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Chave de API do Asaas inválida ou não autorizada."]);
        return;
    }
    
    if ($action === 'create-pix' && $method === 'POST') {
        $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
        $description = $input['description'] ?? "Honorato Barber Shop";
        $referenceId = trim((string)($input['referenceId'] ?? ''));
        $originalAmount = isset($input['originalAmount']) ? (float)$input['originalAmount'] : $amount;
        $discount = max(0, $originalAmount - $amount);
        if ($referenceId === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Referência do booking/pedido é obrigatória."]);
            return;
        }
        $dup = $conn->prepare("SELECT gateway_payment_id, gateway_status FROM payment_history WHERE booking_id = ? AND barbearia_id = ? AND gateway_status NOT IN ('CANCELLED','REFUNDED','DELETED') ORDER BY id DESC LIMIT 1");
        $dup->bind_param("ss", $referenceId, $tenantId);
        executeStmt($dup);
        if ($dup->get_result()->fetch_assoc()) {
            http_response_code(409);
            echo json_encode(["success" => false, "error" => "Já existe uma cobrança PIX para esta referência. Consulte o status do pagamento existente."]);
            return;
        }
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(["error" => "Valor (amount) é obrigatório para gerar faturas."]);
            return;
        }
        $configuredProvider = normalizeGatewayProvider($settings['gatewayProvider'] ?? 'manual');
        
        if ($configuredProvider === 'mercado_pago' && !empty($settings['gatewayToken'])) {
            $idempotencyKey = "honorato_pix_" . hash("sha256", $tenantId . "|" . $referenceId);
            $clientName = trim($input['clientName'] ?? "Cliente");
            $nameParts = explode(" ", $clientName);
            $firstName = $nameParts[0] ?: "Cliente";
            $lastName = implode(" ", array_slice($nameParts, 1)) ?: "Honorato";
            
            $mpBody = json_encode([
                "transaction_amount" => $amount,
                "description" => $description,
                "payment_method_id" => "pix",
                "payer" => [
                    "email" => $input['clientEmail'] ?? "cliente@honoratobarber.com",
                    "first_name" => $firstName,
                    "last_name" => $lastName
                ]
            ]);
            
            $ch = curl_init("https://api.mercadopago.com/v1/payments");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $mpBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $settings['gatewayToken'],
                "Content-Type: application/json",
                "X-Idempotency-Key: " . $idempotencyKey
            ]);
            $mpResp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $mpData = json_decode($mpResp, true) ?: [];
            if ($httpCode >= 200 && $httpCode < 300 && isset($mpData['point_of_interaction']['transaction_data'])) {
                $transData = $mpData['point_of_interaction']['transaction_data'];
                registerGatewayPayment($conn, $referenceId, (string)$mpData['id'], 'mercado_pago', (string)($mpData['status'] ?? 'pending'), $amount, $originalAmount, $discount, $tenantId);
                echo json_encode([
                    "success" => true,
                    "provider" => "mercado_pago",
                    "paymentId" => (string)$mpData['id'],
                    "qrCode" => $transData['qr_code'] ?? '',
                    "qrCodeBase64" => $transData['qr_code_base64'] ?? '',
                    "status" => $mpData['status'] ?? 'pending'
                ]);
                return;
            }
        }
        
        if ($configuredProvider === 'asaas') {
            if (!empty($settings['gatewayToken'])) {
                $cleanToken = trim($settings['gatewayToken']);
                $isSandbox = (strpos($cleanToken, "api_key_test") === 0 || strpos($cleanToken, "$") !== 0);
                $asaasUrl = $isSandbox ? "https://sandbox.asaas.com/api/v3" : "https://www.asaas.com/api/v3";
                
                $searchEmail = $input['clientEmail'] ?? "cliente@honoratobarber.com";
                $custCpf = preg_replace("/[^0-9]/", "", $input['cpfCnpj'] ?? $input['clientCpf'] ?? $input['cpf'] ?? "");
                if (empty($custCpf) || (strlen($custCpf) !== 11 && strlen($custCpf) !== 14)) {
                    $custCpf = "11144477735";
                }
                
                $ch = curl_init($asaasUrl . "/customers?email=" . urlencode($searchEmail));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["access_token: " . $cleanToken]);
                $searchResp = curl_exec($ch);
                curl_close($ch);
                
                $searchData = json_decode($searchResp, true) ?: [];
                $customerId = '';
                if (!empty($searchData['data'])) {
                    $customerId = $searchData['data'][0]['id'];
                } else {
                    $custBody = json_encode([
                        "name" => $input['clientName'] ?? "Cliente Honorato",
                        "email" => $searchEmail,
                        "cpfCnpj" => $custCpf
                    ]);
                    $chC = curl_init($asaasUrl . "/customers");
                    curl_setopt($chC, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($chC, CURLOPT_POST, true);
                    curl_setopt($chC, CURLOPT_POSTFIELDS, $custBody);
                    curl_setopt($chC, CURLOPT_HTTPHEADER, [
                        "access_token: " . $cleanToken,
                        "Content-Type: application/json"
                    ]);
                    $custResp = curl_exec($chC);
                    curl_close($chC);
                    $custData = json_decode($custResp, true) ?: [];
                    $customerId = $custData['id'] ?? '';
                }
                
                if (!empty($customerId)) {
                    $dueDate = date('Y-m-d', strtotime('+1 day'));
                    $payBody = json_encode([
                        "customer" => $customerId,
                        "billingType" => "PIX",
                        "value" => $amount,
                        "dueDate" => $dueDate,
                        "description" => $description,
                        "externalReference" => $referenceId
                    ]);
                    
                    $chP = curl_init($asaasUrl . "/payments");
                    curl_setopt($chP, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($chP, CURLOPT_POST, true);
                    curl_setopt($chP, CURLOPT_POSTFIELDS, $payBody);
                    curl_setopt($chP, CURLOPT_HTTPHEADER, [
                        "access_token: " . $cleanToken,
                        "Content-Type: application/json"
                    ]);
                    $payResp = curl_exec($chP);
                    curl_close($chP);
                    $payData = json_decode($payResp, true) ?: [];
                    
                    if (!empty($payData['id'])) {
                        $paymentId = $payData['id'];
                        $chQ = curl_init($asaasUrl . "/payments/" . $paymentId . "/pixQrCode");
                        curl_setopt($chQ, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($chQ, CURLOPT_HTTPHEADER, ["access_token: " . $cleanToken]);
                        $qrResp = curl_exec($chQ);
                        curl_close($chQ);
                        $qrData = json_decode($qrResp, true) ?: [];
                        
                        $qrPayload = $qrData['payload'] ?? $qrData['qrCode'] ?? $payData['invoiceUrl'] ?? '';
                        $qrImage = $qrData['encodedImage'] ?? $qrData['qrCodeBase64'] ?? null;
                        
                        if (!empty($qrPayload)) {
                            registerGatewayPayment($conn, $referenceId, (string)$paymentId, 'asaas', (string)($payData['status'] ?? 'PENDING'), $amount, $originalAmount, $discount, $tenantId);
                            echo json_encode([
                                "success" => true,
                                "provider" => "asaas",
                                "paymentId" => $paymentId,
                                "qrCode" => $qrPayload,
                                "qrCodeBase64" => $qrImage,
                                "status" => $payData['status'] ?? 'pending'
                            ]);
                            return;
                        }
                    }
                }
            }
        }
        
        http_response_code(503);
        echo json_encode(["success" => false, "approved" => false, "error" => "Pagamento não configurado"]);
        return;
    }

    if ($action === 'create-card' && $method === 'POST') {
        // O servidor não recebe PAN, validade ou CVV. A cobrança por cartão deve ser
        // implementada com checkout hospedado ou tokenização oficial do gateway.
        http_response_code(503);
        echo json_encode([
            "success" => false,
            "approved" => false,
            "error" => "Pagamento com cartão ainda precisa ser configurado com o checkout/tokenização oficial do Asaas."
        ]);
        return;
    }
    
    if ($action === 'create-subscription' && $method === 'POST') {
        http_response_code(503);
        echo json_encode(["success" => false, "active" => false, "benefitsActive" => false, "error" => "Assinatura não configurada"]);
        return;
    }

    if ($action === 'check_status' && !empty($paymentId)) {
        $hist = $conn->prepare("SELECT booking_id, gateway_provider, gateway_status, total FROM payment_history WHERE gateway_payment_id = ? AND barbearia_id = ? LIMIT 1");
        $hist->bind_param("ss", $paymentId, $tenantId);
        executeStmt($hist);
        $paymentRow = $hist->get_result()->fetch_assoc();
        if (!$paymentRow) {
            http_response_code(404);
            echo json_encode(["success" => false, "approved" => false, "error" => "Pagamento não encontrado no payment_history."]);
            return;
        }

        $provider = normalizeGatewayProvider($paymentRow['gateway_provider'] ?? '');
        $gatewayStatus = (string)$paymentRow['gateway_status'];
        $approved = false;
        $querySucceeded = false;
        $configuredProvider = normalizeGatewayProvider($settings['gatewayProvider'] ?? 'manual');
        if (!isGatewayProvider($provider)) {
            http_response_code(422);
            echo json_encode(["success" => false, "approved" => false, "error" => "payment_history contém provedor inválido ou não suportado."]);
            return;
        }
        if ($configuredProvider !== $provider) {
            http_response_code(409);
            echo json_encode(["success" => false, "approved" => false, "status" => $gatewayStatus, "provider" => $provider, "error" => "O provedor configurado atualmente não corresponde ao provedor deste pagamento."]);
            return;
        }

        if ($provider === 'asaas' && !empty($settings['gatewayToken'])) {
            $cleanToken = trim($settings['gatewayToken']);
            $isSandbox = (strpos($cleanToken, "api_key_test") === 0 || strpos($cleanToken, "$") !== 0);
            $asaasUrl = $isSandbox ? "https://sandbox.asaas.com/api/v3" : "https://www.asaas.com/api/v3";
            $ch = curl_init($asaasUrl . "/payments/" . rawurlencode($paymentId));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["access_token: " . $cleanToken]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode === 200) {
                $data = json_decode($resp, true) ?: [];
                $gatewayStatus = (string)($data['status'] ?? 'PENDING');
                $approved = isApprovedGatewayStatus($provider, $gatewayStatus);
                $querySucceeded = true;
            }
        } elseif ($provider === 'mercado_pago' && !empty($settings['gatewayToken'])) {
            $ch = curl_init("https://api.mercadopago.com/v1/payments/" . rawurlencode($paymentId));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . trim($settings['gatewayToken'])]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode === 200) {
                $data = json_decode($resp, true) ?: [];
                $gatewayStatus = (string)($data['status'] ?? 'pending');
                $approved = isApprovedGatewayStatus($provider, $gatewayStatus);
                $querySucceeded = true;
            }
        }

        if (!$querySucceeded) {
            http_response_code(503);
            echo json_encode(["success" => false, "approved" => false, "status" => $gatewayStatus, "error" => "Não foi possível consultar o status real no gateway."]);
            return;
        }

        $newInternalStatus = $approved ? 'paid' : 'pending';
        $up = $conn->prepare("UPDATE payment_history SET gateway_status = ?, status_new = ? WHERE gateway_payment_id = ? AND barbearia_id = ?");
        $up->bind_param("ssss", $gatewayStatus, $newInternalStatus, $paymentId, $tenantId);
        executeStmt($up);

        if ($approved) {
            $bookingId = (string)$paymentRow['booking_id'];
            $book = $conn->prepare("UPDATE bookings SET paymentMethod = 'online', paymentStatus = 'Pago Online' WHERE id = ? AND barbearia_id = ? AND paymentStatus <> 'Pago Online'");
            $book->bind_param("ss", $bookingId, $tenantId);
            executeStmt($book);
            $used = $conn->prepare("UPDATE payment_history SET used_at = COALESCE(used_at, NOW()) WHERE gateway_payment_id = ? AND barbearia_id = ?");
            $used->bind_param("ss", $paymentId, $tenantId);
            executeStmt($used);
        }

        echo json_encode(["success" => true, "provider" => $provider, "paymentId" => $paymentId, "status" => $gatewayStatus, "approved" => $approved]);
        return;
    }
    
    
    http_response_code(404);
    echo json_encode(["error" => "Endpoint de pagamento não encontrado: " . $action]);
}

// FIDELIDADE (FIDELITY PROGRAM)
function handleFidelityRoute($conn, $method, $input, $tenantId) {
    if ($method === 'GET') {
        if (!requireAuthenticated()) { return; }
        $clientId = $_GET['clientId'] ?? '';
        if (empty($clientId)) {
            http_response_code(400);
            echo json_encode(["error" => "ClientId obrigatorio."]);
            return;
        }
        
        $role = $_SESSION['user']['role'] ?? '';
        if ($role === 'client' && $_SESSION['user']['id'] !== $clientId) {
            http_response_code(403);
            echo json_encode(["error" => "Cliente nao pode consultar fidelidade de outro usuario."]);
            return;
        }
        if (!in_array($role, ['admin', 'cashier', 'barber', 'client'], true)) {
            http_response_code(403);
            echo json_encode(["error" => "Permissao negada."]);
            return;
        }

        // Retorna o status atual do programa do cliente
        $stmt = $conn->prepare("SELECT * FROM fidelity_program WHERE client_id = ? AND barbearia_id = ?");
        $stmt->bind_param("ss", $clientId, $tenantId);
        executeStmt($stmt);
        $res = $stmt->get_result()->fetch_assoc();
        
        // Retorna histórico
        $stmtH = $conn->prepare("SELECT * FROM fidelity_history WHERE client_id = ? AND barbearia_id = ? ORDER BY created_at DESC");
        $stmtH->bind_param("ss", $clientId, $tenantId);
        executeStmt($stmtH);
        $resH = $stmtH->get_result();
        $history = [];
        while ($row = $resH->fetch_assoc()) {
            $history[] = $row;
        }
        
        echo json_encode([
            "program" => $res,
            "history" => $history
        ]);
    } elseif ($method === 'POST') {
        // Atualiza ou adiciona pontos ao programa
        if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'cashier'])) {
            http_response_code(403);
            echo json_encode(["error" => "Acesso restrito."]);
            return;
        }
        
        $clientId = $input['clientId'] ?? '';
        $pointsChanged = (int)($input['pointsChanged'] ?? 0);
        $action = $input['action'] ?? 'add'; // 'add' ou 'use'
        $desc = $input['description'] ?? 'Pontos recebidos por servico realizado';
        
        if (empty($clientId) || $pointsChanged === 0) {
            http_response_code(400);
            echo json_encode(["error" => "Campos invalidos."]);
            return;
        }
        
        $conn->begin_transaction();
        try {
            // Busca se o programa já existe
            $stmt = $conn->prepare("SELECT * FROM fidelity_program WHERE client_id = ? AND barbearia_id = ?");
            $stmt->bind_param("ss", $clientId, $tenantId);
            executeStmt($stmt);
            $prog = $stmt->get_result()->fetch_assoc();
            
            $start = date('Y-m-d');
            $end = date('Y-m-d', strtotime('+1 year')); // 1 ano de validade para expiração automática
            
            if ($prog) {
                $collected = (int)$prog['points_collected'];
                $used = (int)$prog['points_used'];
                
                if ($action === 'add') {
                    $collected += $pointsChanged;
                } else {
                    $used += $pointsChanged;
                }
                
                $upd = $conn->prepare("UPDATE fidelity_program SET points_collected = ?, points_used = ?, end_date = ? WHERE client_id = ? AND barbearia_id = ?");
                $upd->bind_param("iisss", $collected, $used, $end, $clientId, $tenantId);
                executeStmt($upd);
            } else {
                $id = uniqid('fid_');
                $collected = $action === 'add' ? $pointsChanged : 0;
                $used = $action === 'use' ? $pointsChanged : 0;
                $req = 10;
                
                $ins = $conn->prepare("INSERT INTO fidelity_program (id, client_id, barbearia_id, start_date, end_date, points_required, points_collected, points_used) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->bind_param("sssssiii", $id, $clientId, $tenantId, $start, $end, $req, $collected, $used);
                executeStmt($ins);
            }
            
            // Grava no histórico de fidelidade
            $hId = uniqid('fidh_');
            $date = date('Y-m-d');
            $signedPoints = $action === 'add' ? $pointsChanged : -$pointsChanged;
            $insH = $conn->prepare("INSERT INTO fidelity_history (id, client_id, barbearia_id, date, points_changed, action, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insH->bind_param("ssssiss", $hId, $clientId, $tenantId, $date, $signedPoints, $action, $desc);
            executeStmt($insH);
            
            $conn->commit();
            writeLog($conn, $_SESSION['user']['id'], $tenantId, 'fidelity_change', "Fidelidade de cliente ID $clientId alterada em $signedPoints pontos");
            echo json_encode(["success" => true]);
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

// HISTÓRICOS AUXILIARES
function handleLogsRoute($conn, $tenantId) {
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(["error" => "Permissao negada."]);
        return;
    }
    $stmt = $conn->prepare("SELECT * FROM logs WHERE barbearia_id = ? ORDER BY created_at DESC LIMIT 100");
    $stmt->bind_param("s", $tenantId);
    executeStmt($stmt);
    $res = $stmt->get_result();
    $logs = [];
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
    echo json_encode($logs);
}


function handleCommissionPaymentsRoute($conn, $method, $input, $tenantId) {
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(["error" => "Permissao negada."]);
        return;
    }

    if ($method === 'GET') {
        $stmt = $conn->prepare("SELECT commission_key, paid FROM commission_payments WHERE barbearia_id = ?");
        $stmt->bind_param("s", $tenantId);
        executeStmt($stmt);
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[$row['commission_key']] = (bool)$row['paid'];
        }
        echo json_encode($items);
        return;
    }

    if ($method === 'PUT') {
        $key = trim((string)($input['key'] ?? ''));
        if ($key === '') {
            http_response_code(400);
            echo json_encode(["error" => "Identificador da comissao ausente."]);
            return;
        }
        $paid = !empty($input['paid']) ? 1 : 0;
        $stmt = $conn->prepare("INSERT INTO commission_payments (commission_key, paid, updated_at, barbearia_id) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE paid = VALUES(paid), updated_at = NOW()");
        $stmt->bind_param("sis", $key, $paid, $tenantId);
        executeStmt($stmt);
        echo json_encode(["success" => true]);
        return;
    }

    http_response_code(405);
    echo json_encode(["error" => "Metodo nao permitido."]);
}

function handleStockHistoryRoute($conn, $tenantId) {
    if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'cashier'])) {
        http_response_code(403);
        echo json_encode(["error" => "Acesso restrito."]);
        return;
    }
    // Opcional: filtro de produtos
    $stmt = $conn->prepare("SELECT s.*, p.name as product_name FROM stock_history s JOIN products p ON s.product_id = p.id WHERE p.barbearia_id = ? ORDER BY s.created_at DESC LIMIT 100");
    $stmt->bind_param("s", $tenantId);
    executeStmt($stmt);
    $res = $stmt->get_result();
    $hist = [];
    while ($row = $res->fetch_assoc()) {
        $hist[] = $row;
    }
    echo json_encode($hist);
}

// --------------------------------------------------------
// 10 - AGENDAMENTOS: FUNÇÃO DE VALIDAÇÃO DE CONFLITO / REGRAS
// --------------------------------------------------------
function validateBookingRule($conn, $input, $excludeId, $tenantId) {
    $barberId = $input['barberId'] ?? '';
    $date = $input['date'] ?? ''; // YYYY-MM-DD
    $time = $input['time'] ?? ''; // HH:MM
    
    // 1. Data e Hora passada
    $bookingDateTime = strtotime("$date $time");
    if ($bookingDateTime < time()) {
        return "Não é possível agendar em uma data ou hora passada.";
    }
    
    // 2. Domingo
    $dayOfWeek = date('w', strtotime($date)); // 0 = Domingo
    if ($dayOfWeek === '0') {
        return "A barbearia encontra-se fechada aos Domingos.";
    }
    
    // 3. Expediente do Barbeiro
    $stmt = $conn->prepare("SELECT * FROM barbers WHERE id = ? AND barbearia_id = ?");
    $stmt->bind_param("ss", $barberId, $tenantId);
    executeStmt($stmt);
    $barber = $stmt->get_result()->fetch_assoc();
    
    if (!$barber) {
        return "Barbeiro nao encontrado.";
    }
    
    if (!$barber['active'] || !$barber['agendaActive']) {
        return "O barbeiro selecionado não está aceitando agendamentos no momento.";
    }
    
    $workDays = json_decode($barber['workDays'], true) ?: [];
    if (!in_array($dayOfWeek, $workDays)) {
        return "Este barbeiro nao trabalha no dia da semana selecionado.";
    }
    
    $workStart = $barber['workStart'];
    $workEnd = $barber['workEnd'];
    $lunchStart = $barber['lunchStart'];
    $lunchEnd = $barber['lunchEnd'];
    
    if ($time < $workStart || $time >= $workEnd) {
        return "Horario selecionado encontra-se fora do expediente do barbeiro ($workStart as $workEnd).";
    }
    
    if ($time >= $lunchStart && $time < $lunchEnd) {
        return "O barbeiro esta em horario de almoço/intervalo ($lunchStart as $lunchEnd).";
    }
    
    // 4. Conflito de Horário (Dois clientes no mesmo horário)
    if (!empty($excludeId)) {
        $q = $conn->prepare("SELECT id FROM bookings WHERE barberId = ? AND date = ? AND time = ? AND id != ? AND status != 'Cancelado' AND barbearia_id = ?");
        $q->bind_param("sssss", $barberId, $date, $time, $excludeId, $tenantId);
    } else {
        $q = $conn->prepare("SELECT id FROM bookings WHERE barberId = ? AND date = ? AND time = ? AND status != 'Cancelado' AND barbearia_id = ?");
        $q->bind_param("ssss", $barberId, $date, $time, $tenantId);
    }
    executeStmt($q);
    $q->store_result();
    if ($q->num_rows > 0) {
        return "Este horário já está agendado com este barbeiro por outro cliente.";
    }
    
    return null; // OK
}

// --------------------------------------------------------
// 11 - HISTÓRICO DE ESTOQUE E ALTERAÇÃO DE PREÇO / STATUS
// --------------------------------------------------------
function logStockChange($conn, $productId, $qtyOld, $qtyNew) {
    $userId = $_SESSION['user']['id'] ?? 'system';
    $userName = $_SESSION['user']['name'] ?? 'Sistema';
    $date = date('Y-m-d');
    $time = date('H:i');
    
    $stmt = $conn->prepare("INSERT INTO stock_history (product_id, usuario_id, user_name, qty_old, qty_new, date, time) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sssiiss", $productId, $userId, $userName, $qtyOld, $qtyNew, $date, $time);
        executeStmt($stmt);
    }
}

function logPaymentChange($conn, $bookingId, $priceOld, $priceNew, $statusOld, $statusNew) {
    if ($priceOld == $priceNew && $statusOld == $statusNew) return;
    
    $userId = $_SESSION['user']['id'] ?? 'system';
    $userName = $_SESSION['user']['name'] ?? 'Sistema';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $date = date('Y-m-d');
    $time = date('H:i');
    
    $stmt = $conn->prepare("INSERT INTO payment_history (booking_id, usuario_id, user_name, ip, date, time, price_old, price_new, status_old, status_new) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssssssddss", $bookingId, $userId, $userName, $ip, $date, $time, $priceOld, $priceNew, $statusOld, $statusNew);
        executeStmt($stmt);
    }
}

// --------------------------------------------------------
// 12 - UPLOAD DE ARQUIVOS (LIMITES, EXTENSÕES E UUID)
// --------------------------------------------------------
function handleUploadRoute() {
    if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'cashier'])) {
        http_response_code(403);
        echo json_encode(["error" => "Permissao negada para upload."]);
        return;
    }
    
    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(["error" => "Nenhum arquivo enviado."]);
        return;
    }
    
    $file = $_FILES['file'];
    
    // Extensões permitidas
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed)) {
        http_response_code(400);
        echo json_encode(["error" => "Apenas imagens nos formatos JPG, JPEG, PNG ou WEBP sao permitidas."]);
        return;
    }
    
    // Limite de 5MB
    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(["error" => "O arquivo excede o limite maximo de 5 MB."]);
        return;
    }
    
    // Renomeia o arquivo utilizando um ID único (UUID / hash de segurança)
    $uuid = bin2hex(random_bytes(16));
    $newName = $uuid . '.' . $ext;
    
    $uploadDir = __DIR__ . '/uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $destPath = $uploadDir . $newName;
    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode([
            "success" => true,
            "url" => "/uploads/" . $newName
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Falha ao salvar o arquivo enviado."]);
    }
}

// --------------------------------------------------------
// 7 - ROTINA DE BACKUP DO BANCO DE DATOS (PROTEÇÃO EXCLUSIVA)
// --------------------------------------------------------
function handleBackupRoute($conn, $tenantId) {
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(["error" => "Permissao negada para geracao de backup."]);
        return;
    }
    
    // Coleta tabelas do sistema
    $tables = ['admins', 'barbers', 'clients', 'bookings', 'products', 'drinks', 'orders', 'payment_settings', 'logs', 'stock_history', 'fidelity_program', 'fidelity_history', 'payment_history'];
    $output = "-- BACKUP COMPLETO DO BANCO DE DATOS - " . date('Y-m-d H:i:s') . "\n";
    $output .= "-- Barbearia ID: $tenantId\n\n";
    
    foreach ($tables as $table) {
        // Coleta definição da tabela
        $resDef = $conn->query("SHOW CREATE TABLE `$table`");
        if ($resDef && $rowDef = $resDef->fetch_row()) {
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $output .= $rowDef[1] . ";\n\n";
        }
        
        // Coleta dados filtrando pela barbearia_id se houver o campo, exceto tabelas gerais ou com tenant respectivo
        $fieldCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'barbearia_id'");
        if ($fieldCheck && $fieldCheck->num_rows > 0) {
            $stmt = $conn->prepare("SELECT * FROM `$table` WHERE barbearia_id = ?");
            $stmt->bind_param("s", $tenantId);
        } else {
            $stmt = $conn->prepare("SELECT * FROM `$table`");
        }
        
        executeStmt($stmt);
        $resData = $stmt->get_result();
        
        while ($rowData = $resData->fetch_assoc()) {
            $keys = array_keys($rowData);
            $values = array_values($rowData);
            
            $escapedValues = array_map(function($v) use ($conn) {
                if ($v === null) return 'NULL';
                return "'" . $conn->real_escape_string($v) . "'";
            }, $values);
            
            $output .= "INSERT INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $escapedValues) . ");\n";
        }
        $output .= "\n";
    }
    
    writeLog($conn, $_SESSION['user']['id'], $tenantId, 'backup_create', "Backup completo gerado pelo administrador");
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="backup_honorato_' . date('Ymd_His') . '.sql"');
    echo $output;
    exit();
}

// --------------------------------------------------------
// AUTO-MIGRATION, INDICES, CHAVES ESTRANGEIRAS E MULTI-TENANCY
// --------------------------------------------------------
function setupMySQLTablesAndTenancy($conn) {
    global $initialAdmins, $defaultPayment;
    
    // Criação das tabelas base
    $queries = [
        "CREATE TABLE IF NOT EXISTS `admins` (
          `id` VARCHAR(50) PRIMARY KEY,
          `name` VARCHAR(100) NOT NULL,
          `email` VARCHAR(100) NOT NULL UNIQUE,
          `passwordHash` VARCHAR(100) NOT NULL,
          `phone` VARCHAR(20) DEFAULT NULL,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `barbers` (
          `id` VARCHAR(50) PRIMARY KEY,
          `name` VARCHAR(100) NOT NULL,
          `avatar` TEXT DEFAULT NULL,
          `bio` TEXT DEFAULT NULL,
          `email` VARCHAR(100) NOT NULL,
          `passwordHash` VARCHAR(100) NOT NULL,
          `phone` VARCHAR(20) DEFAULT NULL,
          `active` BOOLEAN NOT NULL DEFAULT TRUE,
          `agendaActive` BOOLEAN NOT NULL DEFAULT TRUE,
          `workDays` TEXT NOT NULL,
          `workStart` VARCHAR(5) NOT NULL,
          `workEnd` VARCHAR(5) NOT NULL,
          `lunchStart` VARCHAR(5) NOT NULL,
          `lunchEnd` VARCHAR(5) NOT NULL,
          `services` LONGTEXT NOT NULL,
          `commissionPercentage` INT NOT NULL DEFAULT 50,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `clients` (
          `id` VARCHAR(50) PRIMARY KEY,
          `name` VARCHAR(100) NOT NULL,
          `email` VARCHAR(100) NOT NULL UNIQUE,
          `phone` VARCHAR(20) NOT NULL,
          `passwordHash` VARCHAR(100) NOT NULL,
          `createdAt` VARCHAR(50) NOT NULL,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default',
          `avatar` LONGTEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `bookings` (
          `id` VARCHAR(50) PRIMARY KEY,
          `barberId` VARCHAR(50) NOT NULL,
          `barberName` VARCHAR(100) NOT NULL,
          `clientId` VARCHAR(50) NOT NULL,
          `clientName` VARCHAR(100) NOT NULL,
          `clientPhone` VARCHAR(20) NOT NULL,
          `serviceId` VARCHAR(50) NOT NULL,
          `serviceName` VARCHAR(100) NOT NULL,
          `price` DECIMAL(10,2) NOT NULL,
          `date` VARCHAR(10) NOT NULL,
          `time` VARCHAR(5) NOT NULL,
          `paymentMethod` VARCHAR(20) NOT NULL,
          `paymentStatus` VARCHAR(50) NOT NULL,
          `status` VARCHAR(20) NOT NULL,
          `extraServices` TEXT DEFAULT NULL,
          `extraProducts` TEXT DEFAULT NULL,
          `discount` DECIMAL(10,2) DEFAULT 0.00,
          `paymentForm` VARCHAR(50) DEFAULT '',
          `notes` TEXT DEFAULT NULL,
          `cashierProcessed` BOOLEAN DEFAULT FALSE,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `products` (
          `id` VARCHAR(50) PRIMARY KEY,
          `image` TEXT NOT NULL,
          `name` VARCHAR(100) NOT NULL,
          `description` TEXT DEFAULT NULL,
          `price` DECIMAL(10,2) NOT NULL,
          `category` VARCHAR(50) NOT NULL,
          `stock` INT NOT NULL DEFAULT 0,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `drinks` (
          `id` VARCHAR(50) PRIMARY KEY,
          `image` TEXT NOT NULL,
          `name` VARCHAR(100) NOT NULL,
          `description` TEXT DEFAULT NULL,
          `price` DECIMAL(10,2) NOT NULL,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `orders` (
          `id` VARCHAR(50) PRIMARY KEY,
          `clientId` VARCHAR(50) NOT NULL,
          `clientName` VARCHAR(100) NOT NULL,
          `items` LONGTEXT NOT NULL,
          `total` DECIMAL(10,2) NOT NULL,
          `date` VARCHAR(50) NOT NULL,
          `status` VARCHAR(20) NOT NULL,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `commission_payments` (
          `commission_key` VARCHAR(150) NOT NULL,
          `paid` BOOLEAN NOT NULL DEFAULT FALSE,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default',
          PRIMARY KEY (`commission_key`, `barbearia_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `payment_settings` (
          `id` VARCHAR(10) PRIMARY KEY DEFAULT 'default',
          `pixKey` VARCHAR(100) NOT NULL,
          `pixKeyType` VARCHAR(20) NOT NULL,
          `beneficiaryName` VARCHAR(100) NOT NULL,
          `bankName` VARCHAR(100) NOT NULL,
          `city` VARCHAR(100) NOT NULL,
          `gatewayToken` VARCHAR(255) DEFAULT '',
          `gatewayProvider` VARCHAR(50) NOT NULL DEFAULT 'manual',
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `subscription_plans` (
          `id` VARCHAR(50) PRIMARY KEY,
          `name` VARCHAR(100) NOT NULL,
          `price` DECIMAL(10,2) NOT NULL,
          `cycle` VARCHAR(20) NOT NULL DEFAULT 'MONTHLY',
          `description` TEXT DEFAULT NULL,
          `features` LONGTEXT NOT NULL,
          `maxCutsPerMonth` INT NOT NULL DEFAULT 0,
          `maxBeardsPerMonth` INT NOT NULL DEFAULT 0,
          `badge` VARCHAR(50) DEFAULT NULL,
          `active` TINYINT(1) NOT NULL DEFAULT 1,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `service_catalog` (
          `id` VARCHAR(50) PRIMARY KEY,
          `category` VARCHAR(50) NOT NULL,
          `name` VARCHAR(100) NOT NULL,
          `description` TEXT DEFAULT NULL,
          `duration` VARCHAR(50) NOT NULL,
          `price` VARCHAR(50) NOT NULL,
          `popular` TINYINT(1) NOT NULL DEFAULT 0,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        
        // 5 - Tabela de Rate Limit (Brute Force)
        "CREATE TABLE IF NOT EXISTS `login_attempts` (
          `ip` VARCHAR(45) PRIMARY KEY,
          `attempts` INT NOT NULL DEFAULT 1,
          `last_attempt` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `blocked_until` TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // 6 - Tabela de Auditoria (Logs)
        "CREATE TABLE IF NOT EXISTS `logs` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `usuario_id` VARCHAR(50) DEFAULT NULL,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default',
          `acao` VARCHAR(50) NOT NULL,
          `descricao` TEXT NOT NULL,
          `ip` VARCHAR(45) NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // 11 - Tabela de Histórico de Estoque
        "CREATE TABLE IF NOT EXISTS `stock_history` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `product_id` VARCHAR(50) NOT NULL,
          `usuario_id` VARCHAR(50) DEFAULT NULL,
          `user_name` VARCHAR(100) DEFAULT NULL,
          `qty_old` INT NOT NULL,
          `qty_new` INT NOT NULL,
          `date` VARCHAR(10) NOT NULL,
          `time` VARCHAR(5) NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // 18 - Histórico de Pagamentos e Preços
        "CREATE TABLE IF NOT EXISTS `payment_history` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `booking_id` VARCHAR(50) NOT NULL,
          `usuario_id` VARCHAR(50) DEFAULT NULL,
          `user_name` VARCHAR(100) DEFAULT NULL,
          `ip` VARCHAR(45) NOT NULL,
          `date` VARCHAR(10) NOT NULL,
          `time` VARCHAR(5) NOT NULL,
          `price_old` DECIMAL(10,2) NOT NULL,
          `price_new` DECIMAL(10,2) NOT NULL,
          `status_old` VARCHAR(50) NOT NULL,
          `status_new` VARCHAR(50) NOT NULL,
          `gateway_payment_id` VARCHAR(100) DEFAULT NULL,
          `gateway_provider` VARCHAR(30) DEFAULT NULL,
          `gateway_status` VARCHAR(50) DEFAULT NULL,
          `currency` VARCHAR(3) DEFAULT 'BRL',
          `original_amount` DECIMAL(10,2) DEFAULT 0.00,
          `discount` DECIMAL(10,2) DEFAULT 0.00,
          `total` DECIMAL(10,2) DEFAULT 0.00,
          `error_message` VARCHAR(255) DEFAULT NULL,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default',
          `used_at` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // 17 - Tabela de Fidelidade
        "CREATE TABLE IF NOT EXISTS `fidelity_program` (
          `id` VARCHAR(50) PRIMARY KEY,
          `client_id` VARCHAR(50) NOT NULL,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default',
          `start_date` VARCHAR(10) NOT NULL,
          `end_date` VARCHAR(10) NOT NULL,
          `points_required` INT NOT NULL DEFAULT 10,
          `points_collected` INT NOT NULL DEFAULT 0,
          `points_used` INT NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `fidelity_history` (
          `id` VARCHAR(50) PRIMARY KEY,
          `client_id` VARCHAR(50) NOT NULL,
          `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default',
          `date` VARCHAR(10) NOT NULL,
          `points_changed` INT NOT NULL,
          `action` VARCHAR(20) NOT NULL,
          `description` TEXT NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    ];

    foreach ($queries as $q) {
        $conn->query($q);
    }
    
    // Garante que todas as colunas existem (Atualização em bases legadas)
    $tables = ['admins', 'barbers', 'clients', 'bookings', 'products', 'drinks', 'orders', 'payment_settings'];
    foreach ($tables as $t) {
        $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE 'barbearia_id'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE `$t` ADD `barbearia_id` VARCHAR(50) NOT NULL DEFAULT 'default'");
        }
    }
    
    // Garantir que a coluna avatar existe na tabela clients
    $resAvatar = $conn->query("SHOW COLUMNS FROM `clients` LIKE 'avatar'");
    if ($resAvatar && $resAvatar->num_rows === 0) {
        $conn->query("ALTER TABLE `clients` ADD `avatar` LONGTEXT DEFAULT NULL");
    }
    
    // Campos de confirmação real do gateway para pagamentos com cartão
    $paymentHistoryColumns = [
        'gateway_payment_id' => "VARCHAR(100) DEFAULT NULL",
        'gateway_provider' => "VARCHAR(30) DEFAULT NULL",
        'gateway_status' => "VARCHAR(50) DEFAULT NULL",
        'currency' => "VARCHAR(3) DEFAULT 'BRL'",
        'original_amount' => "DECIMAL(10,2) DEFAULT 0.00",
        'discount' => "DECIMAL(10,2) DEFAULT 0.00",
        'total' => "DECIMAL(10,2) DEFAULT 0.00",
        'error_message' => "VARCHAR(255) DEFAULT NULL",
        'barbearia_id' => "VARCHAR(50) NOT NULL DEFAULT 'default'",
        'used_at' => "DATETIME DEFAULT NULL"
    ];
    foreach ($paymentHistoryColumns as $column => $definition) {
        $resColumn = $conn->query("SHOW COLUMNS FROM `payment_history` LIKE '" . $conn->real_escape_string($column) . "'");
        if ($resColumn && $resColumn->num_rows === 0) {
            $conn->query("ALTER TABLE `payment_history` ADD `$column` $definition");
        }
    }
    $idxPayment = $conn->query("SHOW INDEX FROM `payment_history` WHERE Key_name = 'uniq_payment_gateway_id'");
    if ($idxPayment && $idxPayment->num_rows === 0) {
        $conn->query("CREATE UNIQUE INDEX `uniq_payment_gateway_id` ON `payment_history` (`gateway_payment_id`)");
    }

    // 15 - CRIAÇÃO DE ÍNDICES OTIMIZADOS PARA ALTA PERFORMANCE
    $indexes = [
        ['admins', 'idx_admin_email', 'email'],
        ['admins', 'idx_admin_tenant', 'barbearia_id'],
        ['barbers', 'idx_barber_tenant', 'barbearia_id'],
        ['clients', 'idx_client_email', 'email'],
        ['clients', 'idx_client_phone', 'phone'],
        ['clients', 'idx_client_tenant', 'barbearia_id'],
        ['bookings', 'idx_booking_tenant', 'barbearia_id'],
        ['bookings', 'idx_booking_date', 'date'],
        ['bookings', 'idx_booking_barber', 'barberId'],
        ['bookings', 'idx_booking_client', 'clientId'],
        ['products', 'idx_product_tenant', 'barbearia_id'],
        ['drinks', 'idx_drink_tenant', 'barbearia_id'],
        ['orders', 'idx_order_tenant', 'barbearia_id'],
        ['logs', 'idx_log_tenant', 'barbearia_id'],
        ['stock_history', 'idx_stock_product', 'product_id'],
        ['fidelity_program', 'idx_fid_client', 'client_id'],
        ['fidelity_history', 'idx_fidh_client', 'client_id']
    ];
    
    foreach ($indexes as $idx) {
        $tbl = $idx[0];
        $keyName = $idx[1];
        $col = $idx[2];
        
        $chk = $conn->query("SHOW INDEX FROM `$tbl` WHERE Key_name = '$keyName'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `$tbl` ADD INDEX `$keyName` (`$col`)");
        }
    }
    
    // 16 - RESTRIÇÕES DE INTEGRIDADE (FOREIGN KEYS) COM AUTO-VERIFICAÇÃO
    $fks = [
        ['bookings', 'fk_bookings_barber', 'barberId', 'barbers', 'id'],
        ['bookings', 'fk_bookings_client', 'clientId', 'clients', 'id'],
        ['fidelity_program', 'fk_fid_client', 'client_id', 'clients', 'id'],
        ['fidelity_history', 'fk_fidh_client', 'client_id', 'clients', 'id']
    ];
    
    foreach ($fks as $fk) {
        $tbl = $fk[0];
        $name = $fk[1];
        $col = $fk[2];
        $refTbl = $fk[3];
        $refCol = $fk[4];
        
        // Verifica se a chave estrangeira já existe para não quebrar o script
        $chk = $conn->query("SELECT * FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_NAME = '$name' AND TABLE_SCHEMA = DATABASE()");
        if ($chk && $chk->num_rows === 0) {
            // Remove registros órfãos primeiro para garantir que a chave estrangeira seja criada com sucesso
            $conn->query("DELETE t1 FROM `$tbl` t1 LEFT JOIN `$refTbl` t2 ON t1.`$col` = t2.`$refCol` WHERE t2.`$refCol` IS NULL");
            $conn->query("ALTER TABLE `$tbl` ADD CONSTRAINT `$name` FOREIGN KEY (`$col`) REFERENCES `$refTbl` (`$refCol`) ON DELETE CASCADE ON UPDATE CASCADE");
        }
    }

    // Seed de administradores (com hash de senha seguro)
    foreach ($initialAdmins as $adm) {
        $stmt = $conn->prepare("SELECT id, passwordHash FROM admins WHERE id = ?");
        $stmt->bind_param("s", $adm['id']);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows === 0) {
            $hashedPass = password_hash($adm['password'], PASSWORD_BCRYPT);
            $ins = $conn->prepare("INSERT INTO admins (id, name, email, passwordHash, phone, barbearia_id) VALUES (?, ?, ?, ?, ?, 'default')");
            $ins->bind_param("sssss", $adm['id'], $adm['name'], $adm['email'], $hashedPass, $adm['phone']);
            $ins->execute();
        } else {
            // Verifica se a senha do admin no banco ainda está em texto puro e atualiza para hash seguro
            $row = $res->fetch_assoc();
            if (strpos($row['passwordHash'], '$2y$') !== 0) {
                $newHash = password_hash($row['passwordHash'], PASSWORD_BCRYPT);
                $upd = $conn->prepare("UPDATE admins SET passwordHash = ? WHERE id = ?");
                $upd->bind_param("ss", $newHash, $adm['id']);
                $upd->execute();
            }
        }
    }

    // Seed de configurações de pagamento
    $check = $conn->query("SELECT id FROM payment_settings WHERE id = 'default' AND barbearia_id = 'default'");
    if ($check && $check->num_rows === 0) {
        $stmt = $conn->prepare("INSERT INTO payment_settings (id, pixKey, pixKeyType, beneficiaryName, bankName, city, gatewayToken, gatewayProvider, barbearia_id) VALUES ('default', ?, ?, ?, ?, ?, ?, ?, 'default')");
        $stmt->bind_param("sssssss", 
            $defaultPayment['pixKey'], $defaultPayment['pixKeyType'], 
            $defaultPayment['beneficiaryName'], $defaultPayment['bankName'], 
            $defaultPayment['city'], $defaultPayment['gatewayToken'], 
            $defaultPayment['gatewayProvider']
        );
        $stmt->execute();
    }

    // Seed de planos de assinatura iniciais
    $checkPlans = $conn->query("SELECT id FROM subscription_plans WHERE barbearia_id = 'default'");
    if ($checkPlans && $checkPlans->num_rows === 0) {
        $defaultPlans = [
            [
                "id" => "sub-barba-master",
                "name" => "Barba Master VIP",
                "price" => 79.90,
                "cycle" => "MONTHLY",
                "description" => "Mantenha sua barba alinhada e impecável durante todo o mês.",
                "maxCutsPerMonth" => 0,
                "maxBeardsPerMonth" => 4,
                "badge" => "RECOMENDADO",
                "features" => json_encode([
                    "Até 4 barbas no mês",
                    "Toalha quente e pós-barba premium",
                    "Cobrança recorrente via Asaas",
                    "Desconto em produtos da loja"
                ])
            ],
            [
                "id" => "sub-corte-gold",
                "name" => "Corte VIP Gold",
                "price" => 89.90,
                "cycle" => "MONTHLY",
                "description" => "Corte e ajuste seu visual com frequência ideal no mês.",
                "maxCutsPerMonth" => 4,
                "maxBeardsPerMonth" => 0,
                "badge" => "MAIS POPULAR",
                "features" => json_encode([
                    "Até 4 cortes no mês",
                    "Lavagem especial e penteado VIP",
                    "Cobrança recorrente via Asaas",
                    "Prioridade nos horários"
                ])
            ],
            [
                "id" => "sub-royal-complete",
                "name" => "Royal Complete VIP",
                "price" => 139.90,
                "cycle" => "MONTHLY",
                "description" => "Combo completo Cabelo e Barba com regalias exclusivas.",
                "maxCutsPerMonth" => 4,
                "maxBeardsPerMonth" => 4,
                "badge" => "EXPERIÊNCIA TOTAL",
                "features" => json_encode([
                    "Até 4 cortes + 4 barbas no mês",
                    "Acesso ao Bar Lounge Exclusivo",
                    "1 Bebida cortesia por visita",
                    "Atendimento prioritário"
                ])
            ]
        ];
        $stmtP = $conn->prepare("INSERT INTO subscription_plans (id, name, price, cycle, description, features, maxCutsPerMonth, maxBeardsPerMonth, badge, active, barbearia_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'default')");
        foreach ($defaultPlans as $p) {
            $stmtP->bind_param("ssdsssiis", $p['id'], $p['name'], $p['price'], $p['cycle'], $p['description'], $p['features'], $p['maxCutsPerMonth'], $p['maxBeardsPerMonth'], $p['badge']);
            $stmtP->execute();
        }
    }

    // Seed de catálogo de serviços inicial
    $checkCat = $conn->query("SELECT id FROM service_catalog WHERE barbearia_id = 'default'");
    if ($checkCat && $checkCat->num_rows === 0) {
        $defaultCatalog = [
            ["s1", "Cortes", "Corte Masculino Premium", "Atendimento personalizado com visagismo, lavagem relaxante com xampu específico e finalização com pomada importada.", "30-40 min", "R$ 45,00", 1],
            ["s2", "Barba", "Barba & Navalhoterapia", "Ritual completo com toalhas quentes aromáticas, esfoliação facial, óleos hidratantes e alinhamento milimétrico na navalha.", "30 min", "R$ 35,00", 0],
            ["s3", "Combos", "Combo Royal (Corte + Barba)", "O ritual definitivo do homem moderno. Experiência completa de corte visagista + barba premium com navalhoterapia.", "60 min", "R$ 75,00", 1],
            ["s4", "Cortes", "Degradê Navalhado / Fade", "Técnica de transição suave com acabamento perfeito na navalha, para um visual limpo e marcante.", "40 min", "R$ 50,00", 0],
            ["s5", "Cortes", "Corte Infantil (Até 12 anos)", "Atendimento paciencioso com ambiente agradável para deixar os pequenos no estilo.", "30 min", "R$ 40,00", 0],
            ["s6", "Combos", "Pezinho + Barba Simples", "Manutenção rápida de acabamento do cabelo combinada com ajuste de barba.", "30 min", "R$ 45,00", 0]
        ];
        $stmtC = $conn->prepare("INSERT INTO service_catalog (id, category, name, description, duration, price, popular, barbearia_id) VALUES (?, ?, ?, ?, ?, ?, ?, 'default')");
        foreach ($defaultCatalog as $c) {
            $stmtC->bind_param("ssssssi", $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6]);
            $stmtC->execute();
        }
    }
}
