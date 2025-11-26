<?php
// mms/venues/add.php
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
$address = '';
$capacity = '';
$virtual_link = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name         = trim($_POST['name'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $capacity     = trim($_POST['capacity'] ?? '');
    $virtual_link = trim($_POST['virtual_link'] ?? '');

    if ($name === '') {
        $errors[] = 'Venue name is required.';
    }

    if (!$errors) {
        $sql = "INSERT INTO venues (name, address, capacity, virtual_link)
                VALUES (:name, :address, :capacity, :virtual_link)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name'         => $name,
            ':address'      => $address ?: null,
            ':capacity'     => $capacity !== '' ? (int)$capacity : null,
            ':virtual_link' => $virtual_link ?: null,
        ]);

        flash_set('success', 'Venue added successfully.');
        header('Location: list.php');
        exit;
    }
}

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Add Venue</h3>
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
    <label class="form-label">Address</label>
    <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($address) ?></textarea>
  </div>

  <div class="mb-3">
    <label class="form-label">Capacity</label>
    <input type="number" name="capacity" class="form-control"
           value="<?= htmlspecialchars($capacity) ?>">
  </div>

  <div class="mb-3">
    <label class="form-label">Virtual link (for online meetings)</label>
    <input type="url" name="virtual_link" class="form-control"
           value="<?= htmlspecialchars($virtual_link) ?>">
  </div>

  <button type="submit" class="btn btn-primary">
    <i class="bi bi-check2-circle"></i> Save
  </button>
</form>

<?php include __DIR__ . '/../footer.php'; ?>
