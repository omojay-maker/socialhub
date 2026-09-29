<?php
class UserModel {
    public static function findByEmail(string $email): ?array {
        $stmt=db()->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
        $stmt->execute([$email]); $r=$stmt->fetch(); return $r?:null;
    }
    public static function find(int $id): ?array {
        $stmt=db()->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$id]); $r=$stmt->fetch(); return $r?:null;
    }
    public static function all(): array { return db()->query("SELECT id,name,email,role,is_active,last_login_at,created_at FROM users ORDER BY id DESC")->fetchAll(); }
    public static function create(array $d): int {
        $stmt=db()->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)");
        $stmt->execute([$d['name'],$d['email'],$d['password'],$d['role']]); return (int)db()->lastInsertId();
    }
    public static function update(int $id,array $d): void {
        $stmt=db()->prepare("UPDATE users SET name=?,email=?,role=?,is_active=? WHERE id=?");
        $stmt->execute([$d['name'],$d['email'],$d['role'],$d['is_active']??1,$id]);
    }
    public static function delete(int $id): void { db()->prepare("DELETE FROM users WHERE id=?")->execute([$id]); }
}
