<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/GradeRepository.php';

final class ProjectService
{
    public static function generateUniqueSlug(?PDO $pdo = null): string
    {
        $pdo ??= db();
        $exists = $pdo->prepare('SELECT 1 FROM query_projects WHERE slug = ? LIMIT 1');
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $slug = 'project-' . date('Ymd') . '-' . bin2hex(random_bytes(4));
            $exists->execute([$slug]);
            if ($exists->fetchColumn() === false) {
                return $slug;
            }
        }
        throw new RuntimeException('暂时无法生成项目标识，请稍后重试。');
    }

    public static function save(array $input, ?int $projectId = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $notice = trim((string) ($input['notice'] ?? ''));
        $status = (string) ($input['status'] ?? 'disabled');
        $sortOrder = (int) ($input['sort_order'] ?? 0);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 150) {
            throw new InvalidArgumentException('请填写150个字符以内的项目名称。');
        }
        if (!in_array($status, ['enabled', 'disabled', 'archived'], true)) {
            throw new InvalidArgumentException('项目状态无效。');
        }
        if (mb_strlen($description, 'UTF-8') > 5000 || mb_strlen($notice, 'UTF-8') > 5000) {
            throw new InvalidArgumentException('项目说明或前台提示内容过长。');
        }

        $pdo = db();
        if (($projectId ?? 0) > 0) {
            $current = GradeRepository::project((int) $projectId, true);
            if ($current === null) {
                throw new InvalidArgumentException('查询项目不存在。');
            }
            $stmt = $pdo->prepare('UPDATE query_projects SET name = ?, description = ?, notice = ?, status = ?, sort_order = ? WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$name, $description, $notice, $status, $sortOrder, $projectId]);
            audit_log('query_project_updated', 'query_project', (int) $projectId, true, [
                'status' => $status,
                'slug_unchanged' => (string) $current['slug'],
            ]);
            return ['id' => (int) $projectId, 'slug' => (string) $current['slug'], 'created' => false];
        }

        $slug = self::generateUniqueSlug($pdo);
        $stmt = $pdo->prepare('INSERT INTO query_projects (name, slug, description, notice, status, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $slug, $description, $notice, $status, $sortOrder]);
        $id = (int) $pdo->lastInsertId();
        audit_log('query_project_created', 'query_project', $id, true, ['status' => $status, 'slug' => $slug]);
        return ['id' => $id, 'slug' => $slug, 'created' => true];
    }
}
