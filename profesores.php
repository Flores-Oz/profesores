<?php
/****************************************************
 * CRUD Profesores - PHP único con PDO + Bootstrap  * 
 ****************************************************/

declare(strict_types=1);
session_start();

/* ====== CONFIG DB ====== */
$DB_HOST = "192.168.1.53";
$DB_NAME = "proyecto";
$DB_USER = "checha";
$DB_PASS = "admin1234";

/* ====== CONFIG TABLA ======
   Cambia si tu tabla se llama distinto.
   El script autodetecta columnas y PK. */
$TABLE = "profesores";

/* ====== UTILIDADES ====== */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
        if (!$ok) { http_response_code(400); exit("CSRF inválido"); }
    }
}

/* ====== CONEXIÓN ====== */
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo "<pre style='padding:1rem;color:#b00;background:#fff5f5;border:1px solid #f7d8d8'>".
         "No se pudo conectar a la base de datos:\n".h($e->getMessage()).
         "</pre>";
    exit;
}

/* ====== METADATA DE TABLA ======
   - Detecta columnas, primary key y tipos para inputs */
try {
    $colsStmt = $pdo->query("SHOW FULL COLUMNS FROM `$TABLE`"); // Field, Type, Null, Key, Default, Extra, Comment
    $columns = $colsStmt->fetchAll();
    if (!$columns) { throw new RuntimeException("La tabla `$TABLE` no tiene columnas o no existe."); }

    $pk = null;
    $writeCols = []; // columnas editables (no PK AI)
    foreach ($columns as $c) {
        $field = $c['Field'];
        $isPK  = ($c['Key'] === 'PRI');
        $isAI  = (stripos((string)$c['Extra'], 'auto_increment') !== false);
        if ($isPK && $pk === null) $pk = $field;
        if (!($isPK && $isAI)) $writeCols[] = $c;
    }
    if (!$pk) { throw new RuntimeException("No se detectó PRIMARY KEY en `$TABLE`."); }
} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre style='padding:1rem;color:#b00;background:#fff5f5;border:1px solid #f7d8d8'>".
         "Error leyendo metadatos de la tabla `$TABLE`:\n".h($e->getMessage()).
         "</pre>";
    exit;
}

/* ====== MAPEO DE INPUTS ====== */
function input_for_type(string $mysqlType): string {
    $t = strtolower($mysqlType);
    if (str_contains($t, 'tinyint(1)')) return 'checkbox';
    if (str_contains($t, 'int') || str_contains($t, 'decimal') || str_contains($t, 'float') || str_contains($t, 'double')) return 'number';
    if (str_contains($t, 'date') && !str_contains($t, 'time')) return 'date';
    if (str_contains($t, 'datetime') || str_contains($t, 'timestamp')) return 'datetime-local';
    if (str_contains($t, 'time') && !str_contains($t, 'date')) return 'time';
    if (str_contains($t, 'text') || str_contains($t, 'blob')) return 'textarea';
    if (str_contains($t, 'enum')) return 'select-enum';
    return 'text';
}
function parse_enum_options(string $mysqlType): array {
    // enum('A','B','C')
    if (preg_match("/enum\\((.+)\\)/i", $mysqlType, $m)) {
        $raw = $m[1];
        $opts = [];
        foreach (preg_split("/,(?=(?:[^']*'[^']*')*[^']*$)/", $raw) as $part) {
            $part = trim($part);
            $part = trim($part, "'");
            $opts[] = $part;
        }
        return $opts;
    }
    return [];
}

/* ====== ACCIONES ====== */
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
verify_csrf();

$flash = function (string $type, string $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
};
$readFlash = function () {
    $x = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $x;
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($action === 'create') {
            // Insert dinámico
            $fields = [];
            $place  = [];
            $values = [];
            foreach ($writeCols as $c) {
                $f = $c['Field'];
                $type = input_for_type($c['Type']);
                if ($type === 'checkbox') {
                    $val = isset($_POST[$f]) ? 1 : 0;
                } else {
                    $val = $_POST[$f] ?? null;
                    $val = ($val === '') ? null : $val;
                }
                $fields[] = "`$f`";
                $place[]  = ":$f";
                $values[":$f"] = $val;
            }
            $sql = "INSERT INTO `$TABLE` (".implode(',', $fields).") VALUES (".implode(',', $place).")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            $flash('success', 'Registro creado correctamente.');
            header("Location: ?"); exit;
        }

        if ($action === 'update') {
            $id = $_POST[$pk] ?? null;
            if (!$id) throw new InvalidArgumentException("ID faltante para actualizar ($pk).");
            $sets = [];
            $values = [];
            foreach ($writeCols as $c) {
                $f = $c['Field'];
                $type = input_for_type($c['Type']);
                if ($type === 'checkbox') {
                    $val = isset($_POST[$f]) ? 1 : 0;
                } else {
                    $val = $_POST[$f] ?? null;
                    $val = ($val === '') ? null : $val;
                }
                $sets[] = "`$f` = :$f";
                $values[":$f"] = $val;
            }
            $values[":_id"] = $id;
            $sql = "UPDATE `$TABLE` SET ".implode(',', $sets)." WHERE `$pk` = :_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            $flash('success', 'Registro actualizado correctamente.');
            header("Location: ?"); exit;
        }

        if ($action === 'delete') {
            $id = $_POST[$pk] ?? null;
            if (!$id) throw new InvalidArgumentException("ID faltante para eliminar ($pk).");
            $stmt = $pdo->prepare("DELETE FROM `$TABLE` WHERE `$pk` = :id");
            $stmt->execute([':id' => $id]);
            $flash('success', 'Registro eliminado.');
            header("Location: ?"); exit;
        }
    }
} catch (Throwable $e) {
    $flash('danger', 'Error: '.$e->getMessage());
    header("Location: ?"); exit;
}

/* ====== LISTADO (búsqueda + orden + paginación) ====== */
$search = trim((string)($_GET['q'] ?? ''));
$sort   = $_GET['sort'] ?? $pk;
$dir    = strtoupper($_GET['dir'] ?? 'DESC');
$dir    = in_array($dir, ['ASC','DESC']) ? $dir : 'DESC';

$colNames = array_column($columns, 'Field');
if (!in_array($sort, $colNames, true)) $sort = $pk;

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = '';
$params = [];
if ($search !== '') {
    // Buscar en todas las columnas de texto
    $likeParts = [];
    foreach ($columns as $c) {
        $likeParts[] = "`{$c['Field']}` LIKE :q";
    }
    $where = "WHERE ".implode(" OR ", $likeParts);
    $params[':q'] = "%$search%";
}

$total = (function() use ($pdo, $TABLE, $where, $params) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM `$TABLE` $where");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
})();

$sqlList = "SELECT * FROM `$TABLE` $where ORDER BY `$sort` $dir LIMIT :off, :pp";
$stmt = $pdo->prepare($sqlList);
foreach ($params as $k=>$v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->bindValue(':pp',  $perPage, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$pages = (int)ceil($total / $perPage);
$flashMsg = $readFlash();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Profesores · CRUD</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Bootstrap local desde node_modules en la MISMA carpeta del proyecto -->
<link rel="stylesheet" href="node_modules/bootstrap/dist/css/bootstrap.min.css">
<style>
  body { background:#f6f7fb; }
  .table thead th a { text-decoration:none; color:inherit; }
  .card { border-radius:14px; }
</style>
</head>
<body>
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h1 class="h3 mb-1">Profesores</h1>
      <div class="text-secondary">Base de datos: <code><?=h($DB_NAME)?></code> · Tabla: <code><?=h($TABLE)?></code></div>
    </div>
    <div>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreate">
        ➕ Nuevo
      </button>
    </div>
  </div>

  <?php if ($flashMsg): ?>
    <div class="alert alert-<?=h($flashMsg['type'])?> alert-dismissible fade show" role="alert">
      <?=h($flashMsg['msg'])?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form class="row g-2 mb-3" method="get">
        <div class="col-md-6">
          <input name="q" class="form-control" placeholder="Buscar en todos los campos…" value="<?=h($search)?>">
        </div>
        <div class="col-md-6 text-md-end">
          <button class="btn btn-outline-secondary">Buscar</button>
          <a class="btn btn-outline-dark" href="?">Limpiar</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <?php foreach ($colNames as $c): 
                $newDir = ($sort === $c && $dir === 'ASC') ? 'DESC' : 'ASC';
                $arrow  = ($sort === $c) ? ($dir === 'ASC' ? '↑' : '↓') : '';
                $qs = http_build_query(['q'=>$search, 'sort'=>$c, 'dir'=>$newDir, 'page'=>1]);
              ?>
                <th><a href="?<?=$qs?>"><?=h($c)?> <?=$arrow?></a></th>
              <?php endforeach; ?>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="<?=count($colNames)+1?>" class="text-center text-muted py-4">No se encontraron registros.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <?php foreach ($colNames as $c): ?>
                  <td><?=h($r[$c])?></td>
                <?php endforeach; ?>
                <td class="text-end">
                  <!-- Botón Editar -->
                  <button class="btn btn-sm btn-outline-primary"
                          data-bs-toggle="modal"
                          data-bs-target="#modalEdit"
                          data-row='<?=h(json_encode($r, JSON_UNESCAPED_UNICODE))?>'>
                    Editar
                  </button>

                  <!-- Botón Eliminar -->
                  <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este registro?');">
                    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="<?=h($pk)?>" value="<?=h($r[$pk])?>">
                    <button class="btn btn-sm btn-outline-danger">Eliminar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1): ?>
        <nav>
          <ul class="pagination justify-content-center">
            <?php
            $baseQS = ['q'=>$search, 'sort'=>$sort, 'dir'=>$dir];
            for ($p=1; $p <= $pages; $p++):
              $qs = http_build_query($baseQS + ['page'=>$p]);
            ?>
              <li class="page-item <?=$p===$page?'active':''?>">
                <a class="page-link" href="?<?=$qs?>"><?=$p?></a>
              </li>
            <?php endfor; ?>
          </ul>
        </nav>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ====== MODAL CREAR ====== -->
<div class="modal fade" id="modalCreate" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="create">
      <div class="modal-header">
        <h5 class="modal-title">Nuevo registro</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php foreach ($writeCols as $c):
            $f = $c['Field']; $t = $c['Type']; $nullable = ($c['Null']==='YES');
            $input = input_for_type($t);
            $enumOpts = ($input==='select-enum') ? parse_enum_options($t) : [];
        ?>
          <div class="mb-3">
            <label class="form-label"><?=h($f)?> <?=!$nullable?'<span class="text-danger">*</span>':''?></label>
            <?php if ($input === 'textarea'): ?>
              <textarea class="form-control" name="<?=h($f)?>" rows="3" <?=!$nullable?'required':''?>></textarea>
            <?php elseif ($input === 'checkbox'): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="<?=h($f)?>" value="1" id="c_<?=h($f)?>">
                <label class="form-check-label" for="c_<?=h($f)?>">Activar</label>
              </div>
            <?php elseif ($input === 'select-enum'): ?>
              <select class="form-select" name="<?=h($f)?>" <?=!$nullable?'required':''?>>
                <option value="">— Seleccionar —</option>
                <?php foreach ($enumOpts as $opt): ?>
                  <option value="<?=h($opt)?>"><?=h($opt)?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input class="form-control" type="<?=$input?>" name="<?=h($f)?>" <?=!$nullable?'required':''?>>
            <?php endif; ?>
            <?php if (!empty($c['Comment'])): ?>
              <div class="form-text"><?=h($c['Comment'])?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
        <button class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- ====== MODAL EDITAR ====== -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post" id="formEdit">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="<?=h($pk)?>" id="edit_pk">
      <div class="modal-header">
        <h5 class="modal-title">Editar registro</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php foreach ($writeCols as $c):
            $f = $c['Field']; $t = $c['Type']; $nullable = ($c['Null']==='YES');
            $input = input_for_type($t);
            $enumOpts = ($input==='select-enum') ? parse_enum_options($t) : [];
        ?>
          <div class="mb-3">
            <label class="form-label"><?=h($f)?> <?=!$nullable?'<span class="text-danger">*</span>':''?></label>
            <?php if ($input === 'textarea'): ?>
              <textarea class="form-control" name="<?=h($f)?>" id="edit_<?=h($f)?>" rows="3" <?=!$nullable?'required':''?>></textarea>
            <?php elseif ($input === 'checkbox'): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="<?=h($f)?>" id="edit_<?=h($f)?>">
                <label class="form-check-label" for="edit_<?=h($f)?>">Activar</label>
              </div>
            <?php elseif ($input === 'select-enum'): ?>
              <select class="form-select" name="<?=h($f)?>" id="edit_<?=h($f)?>" <?=!$nullable?'required':''?>>
                <option value="">— Seleccionar —</option>
                <?php foreach ($enumOpts as $opt): ?>
                  <option value="<?=h($opt)?>"><?=h($opt)?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input class="form-control" type="<?=$input?>" name="<?=h($f)?>" id="edit_<?=h($f)?>" <?=!$nullable?'required':''?>>
            <?php endif; ?>
            <?php if (!empty($c['Comment'])): ?>
              <div class="form-text"><?=h($c['Comment'])?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
        <button class="btn btn-primary">Actualizar</button>
      </div>
    </form>
  </div>
</div>

<!-- Bootstrap local desde node_modules en la MISMA carpeta del proyecto -->
<script src="node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Rellenar modal de edición con los datos de la fila */
const modalEdit = document.getElementById('modalEdit');
modalEdit.addEventListener('show.bs.modal', event => {
  const btn = event.relatedTarget;
  const data = JSON.parse(btn.getAttribute('data-row'));
  document.getElementById('edit_pk').value = data['<?=h($pk)?>'];

  <?php foreach ($writeCols as $c):
        $f = $c['Field']; $t = $c['Type']; $input = input_for_type($t); ?>
    (function(){
      const el = document.getElementById('edit_<?=h($f)?>');
      if (!el) return;
      const val = (data['<?=h($f)?>'] ?? '');
      <?php if ($input === 'checkbox'): ?>
        el.checked = (String(val) === '1' || String(val).toLowerCase() === 'true');
      <?php elseif ($input === 'datetime-local'): ?>
        // Normalizar si viene "YYYY-mm-dd HH:ii:ss"
        if (val && val.length >= 16) {
          const norm = val.replace(' ', 'T').slice(0,16);
          el.value = norm;
        } else { el.value = ''; }
      <?php else: ?>
        el.value = val === null ? '' : val;
      <?php endif; ?>
    })();
  <?php endforeach; ?>
});
</script>
</body>
</html>
