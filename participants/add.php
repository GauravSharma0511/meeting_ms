<?php
// src/public/participants/add.php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
requireLogin();
$pdo = getPDO();
$designations = $pdo->query("SELECT id,title FROM designations ORDER BY title")->fetchAll();
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) $error = "Invalid CSRF";
    else {
        $name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $designation_id = (int)($_POST['designation_id'] ?? 0);
        if ($name === '') $error = "Name required";
        else {
            $stmt = $pdo->prepare("INSERT INTO participants (full_name,email,phone,designation_id,created_at) VALUES (:n,:e,:p,:d,now())");
            $stmt->execute([':n'=>$name, ':e'=>$email, ':p'=>$phone, ':d'=>$designation_id ?: null]);
            flash_set('success','Participant added');
            header('Location: /mms/participants/list.php'); exit;
        }
    }
}

include __DIR__ . '/../header.php';
?>
<h3>Add participant</h3>
<?php if($error): ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php endif; ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?=csrf_token()?>">
  <div class="form-group"><label>Full name</label><input name="full_name" class="form-control" required></div>
  <div class="form-group"><label>Email</label><input name="email" class="form-control"></div>
  <div class="form-group"><label>Phone</label><input name="phone" class="form-control"></div>
  <div class="form-group"><label>Designation</label>
    <select name="designation_id" class="form-control">
      <option value="">--none--</option>
      <?php foreach($designations as $d): ?>
        <option value="<?=$d['id']?>"><?=htmlspecialchars($d['title'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-primary">Add</button>
</form>
<?php include __DIR__ . '/../footer.php'; ?>
