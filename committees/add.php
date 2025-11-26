<?php
// mms/committees/add.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();
$user  = $_SESSION['user'] ?? null;
$role  = $user['role'] ?? '';
$isSuper = ($role === 'superuser');

if (!$isSuper) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

$pdo = getPDO();

$name = '';
$description = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $errors[] = 'Committee name is required.';
    }

    if (!$errors) {
        $sql = "INSERT INTO committees (name, description, created_by_user_id)
                VALUES (:name, :description, :created_by)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name'        => $name,
            ':description' => $description ?: null,
            ':created_by'  => $user['id'] ?? null,
        ]);

        flash_set('success', 'Committee created successfully.');
        header('Location: list.php');
        exit;
    }
}

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Add Committee</h3>
  <a href="list.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="card card-body">
  <div class="mb-3">
    <label class="form-label">Name<span class="text-danger">*</span></label>
    <input type="text" name="name" class="form-control"
           value="<?= htmlspecialchars($name) ?>" required>
  </div>

  <div class="mb-3">
    <label class="form-label">Description</label>
    <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($description) ?></textarea>
  </div>

  <button type="submit" class="btn btn-primary">
    <i class="bi bi-check2-circle"></i> Save
  </button>
</form>

<?php include __DIR__ . '/../footer.php'; ?>
