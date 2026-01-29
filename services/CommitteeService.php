<?php

class CommitteeService
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* =====================================================
       MAIN ENTRY
       ===================================================== */
    public function createCommittee(
        $committeeName,
        $description,
        $createdByUserId,
        array $members
    ) {
        if (trim($committeeName) === '') {
            throw new Exception('Committee name is required');
        }

        // if ((int)$adminUserId <= 0) {
        //     throw new Exception('Admin user is required');
        // }

        $this->pdo->beginTransaction();

        try {

            /* 1️⃣ Create committee */
            $committeeId = $this->insertCommittee(
                $committeeName,
                $description,
                $createdByUserId
            );
      /* 5️⃣ Members */
foreach ($members as $m) {

    if (empty($m['full_name'])) {
        continue;
    }

    if (empty($m['designation_id'])) {
        throw new Exception('Designation missing for member');
    }

    // 🔒 ADMIN RULE: admin MUST be registry
    if (($m['add_as'] ?? '') === 'admin' && $m['participant_type'] !== 'registry') {
        throw new Exception('Only registry users can be admin');
    }

    // 1️⃣ ensure participant
    $participantId = $this->ensureParticipant($m);

    // 2️⃣ if admin → ensure user + admin mappings
    if (($m['add_as'] ?? '') === 'admin') {

        // RJCODE → users.id
        $userId = $this->ensureUserFromRegistry($m);

        // committee admin (LOGIN authority)
        $this->addCommitteeAdmin($committeeId, $userId);

        // committee user (ADMIN role)
        $this->addAdminParticipant($committeeId, $participantId);

    } else {
        // normal member
        $this->addMember($committeeId, $participantId);
    }
}



            $this->pdo->commit();
            return (int)$committeeId;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
private function ensureUserFromRegistry(array $m): int
{
    if (empty($m['external_id'])) {
        throw new Exception('Registry RJ code missing');
    }

    $rjcode = $m['external_id'];   // RJ code
    $name   = $m['full_name'];
    $email  = $m['email'] ?? null;

    // 1️⃣ Check existing user using username (RJCODE)
    $stmt = $this->pdo->prepare("
        SELECT id FROM users WHERE username = :username
    ");
    $stmt->execute([':username' => $rjcode]);

    $uid = $stmt->fetchColumn();
    if ($uid) {
        return (int)$uid;
    }

    // 2️⃣ Create user with username = RJCODE
    $stmt = $this->pdo->prepare("
        INSERT INTO users (full_name, email, username, role)
        VALUES (:name, :email, :username, 'admin')
        RETURNING id
    ");
    $stmt->execute([
        ':name'     => $name,
        ':email'    => $email,
        ':username' => $rjcode
    ]);

    return (int)$stmt->fetchColumn();
}


    /* =====================================================
       INTERNAL METHODS
       ===================================================== */

private function insertCommittee($name, $description, $createdByUserId)
{
    // 🔒 DUPLICATE COMMITTEE CHECK
    $chk = $this->pdo->prepare("
        SELECT id FROM committees WHERE name = :name
    ");
    $chk->execute([':name' => $name]);

    if ($chk->fetchColumn()) {
        throw new Exception("Committee with this name already exists");
    }

    // ✅ INSERT COMMITTEE
    $stmt = $this->pdo->prepare("
        INSERT INTO committees (name, description, created_by_user_id)
        VALUES (:name, :description, :uid)
        RETURNING id
    ");

    $stmt->execute([
        ':name'        => $name,
        ':description' => $description ?: null,
        ':uid'         => $createdByUserId
    ]);

    return (int)$stmt->fetchColumn();
}



    /* =====================================================
       ADMIN → PARTICIPANT
       ===================================================== */
    //* =====================================================
//    REGISTRY ADMIN → USER
//    ===================================================== */



    private function ensureAdminParticipant($userId)
{
    $stmt = $this->pdo->prepare("
        SELECT id, full_name, username, email
        FROM users
        WHERE id = :uid
    ");
    $stmt->execute([':uid' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('Invalid admin user');
    }

    // already mapped?
    $chk = $this->pdo->prepare("
        SELECT id FROM participants
        WHERE external_source = 'users'
          AND external_id = :eid
    ");
    $chk->execute([':eid' => 'user:' . $userId]);
    $pid = (int)$chk->fetchColumn();
    if ($pid > 0) return $pid;

    $fullName = trim($user['full_name']) ?: $user['username'];

    $ins = $this->pdo->prepare("
        INSERT INTO participants
        (full_name, email, participant_type, external_source, external_id)
        VALUES
        (:name, :email, 'internal', 'users', :eid)
        RETURNING id
    ");
    $ins->execute([
        ':name'  => $fullName,
        ':email' => $user['email'],
        ':eid'   => 'user:' . $userId
    ]);

    return (int)$ins->fetchColumn();
}


    /* =====================================================
       MEMBERS / API / MANUAL
       ===================================================== */
    private function ensureParticipant(array $m)
    {
        // external_id
        if (!empty($m['external_id'])) {
            $s = $this->pdo->prepare("
                SELECT id FROM participants
                WHERE external_id = :eid
            ");
            $s->execute([':eid' => $m['external_id']]);
            $pid = (int)$s->fetchColumn();
            if ($pid) return $pid;
        }

        // email
        if (!empty($m['email'])) {
            $s = $this->pdo->prepare("
                SELECT id FROM participants
                WHERE email = :email
            ");
            $s->execute([':email' => $m['email']]);
            $pid = (int)$s->fetchColumn();
            if ($pid) return $pid;
        }

        // phone
        if (!empty($m['phone'])) {
            $s = $this->pdo->prepare("
                SELECT id FROM participants
                WHERE phone = :phone
            ");
            $s->execute([':phone' => $m['phone']]);
            $pid = (int)$s->fetchColumn();
            if ($pid) return $pid;
        }

        $ins = $this->pdo->prepare("
            INSERT INTO participants
            (full_name, email, phone, designation_id,
             participant_type, external_source, external_id)
            VALUES
            (:name, :email, :phone, :designation,
             :ptype, :source, :eid)
            RETURNING id
        ");
        $ins->execute([
            ':name'        => $m['full_name'],
            ':email'       => $m['email'] ?? null,
            ':phone'       => $m['phone'] ?? null,
            ':designation' => $m['designation_id'],
            ':ptype'       => $m['participant_type'],
            ':source'      => $m['external_source'] ?? null,
            ':eid'         => $m['external_id'] ?? null
        ]);

        return (int)$ins->fetchColumn();
    }

    /* =====================================================
       COMMITTEE LINKS
       ===================================================== */
    private function addAdminParticipant($committeeId, $participantId)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO committee_users
            (committee_id, participant_id, role_in_committee)
            VALUES (:cid, :pid, 'admin')
            
        ");
        $stmt->execute([
            ':cid' => $committeeId,
            ':pid' => $participantId
        ]);
    }

    private function addCommitteeAdmin($committeeId, $userId)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO committee_admins (committee_id, user_id)
            VALUES (:cid, :uid)
            
        ");
        $stmt->execute([
            ':cid' => $committeeId,
            ':uid' => $userId
        ]);
    }

    private function addMember($committeeId, $participantId)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO committee_users
            (committee_id, participant_id, role_in_committee)
            VALUES (:cid, :pid, 'member')
            
        ");
        $stmt->execute([
            ':cid' => $committeeId,
            ':pid' => $participantId
        ]);
    }
}
