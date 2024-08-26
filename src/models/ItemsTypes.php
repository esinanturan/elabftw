<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Models;

use DateTimeImmutable;
use Elabftw\Elabftw\OrderingParams;
use Elabftw\Enums\BasePermissions;
use Elabftw\Enums\EntityType;
use Elabftw\Enums\State;
use Elabftw\Exceptions\IllegalActionException;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Services\Filter;
use PDO;

/**
 * The kind of items you can have in the database for a team
 */
class ItemsTypes extends AbstractTemplateEntity
{
    public EntityType $entityType = EntityType::ItemsTypes;

    public function create(
        ?int $template = -1,
        ?string $title = null,
        ?string $body = null,
        ?DateTimeImmutable $date = null,
        ?string $canread = null,
        ?string $canwrite = null,
        array $tags = array(),
        ?int $category = null,
        ?int $status = null,
        ?int $customId = null,
        ?string $metadata = null,
        int $rating = 0,
        bool $forceExpTpl = false,
        string $defaultTemplateHtml = '',
        string $defaultTemplateMd = '',
        // specific to items_types
        ?string $color = null,
    ): int {
        $title = Filter::title($title ?? _('Default'));
        $this->isAdminOrExplode();
        $defaultPermissions = BasePermissions::Team->toJson();
        // TODO have a function for a random cool color? like in status
        $color ??= '29AEB9';

        $sql = 'INSERT INTO items_types(userid, title, body, team, canread, canwrite, canread_target, canwrite_target, color, rating)
            VALUES(:userid, :title, :body, :team, :canread, :canwrite, :canread_target, :canwrite_target, :color, :rating)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':title', $title);
        $req->bindValue(':body', $body);
        $req->bindParam(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindParam(':canread', $defaultPermissions);
        $req->bindParam(':canwrite', $defaultPermissions);
        $req->bindParam(':canread_target', $defaultPermissions);
        $req->bindParam(':canwrite_target', $defaultPermissions);
        $req->bindParam(':color', $color);
        $req->bindParam(':rating', $rating, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $this->Db->lastInsertId();
    }

    public function getDefault(): int
    {
        // there might be none, so create one if needed
        if (empty($this->readAll())) {
            return $this->create();
        }
        // there are no default items_types, so just pick the first one from the team
        $sql = 'SELECT id FROM items_types WHERE team = :team LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return (int) $req->fetchColumn();
    }

    /**
     * SQL to get all items type
     */
    public function readAll(): array
    {
        $sql = 'SELECT id, userid, created_at, modified_at, team, color, title, status, body, ordering, canread, canwrite, canread_target, canwrite_target, metadata, state
            FROM items_types WHERE team = :team AND state = :state ORDER BY ordering ASC';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':state', State::Normal->value, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readOne(): array
    {
        $sql = 'SELECT id, userid, created_at, modified_at, team, color, title, status, body, ordering, canread, canwrite, canread_target, canwrite_target, metadata, state
            FROM items_types WHERE id = :id AND team = :team';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        $req->bindParam(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        $this->entityData = $this->Db->fetch($req);
        // don't check for read permissions for items types as it can be read from many places/users
        //$this->canOrExplode('read');
        // add steps and links in there too
        $this->entityData['steps'] = $this->Steps->readAll();
        $this->entityData['items_links'] = $this->ItemsLinks->readAll();
        $this->entityData['experiments_links'] = $this->ExperimentsLinks->readAll();
        $this->entityData['exclusive_edit_mode'] = $this->ExclusiveEditMode->readOne();
        return $this->entityData;
    }

    public function duplicate(bool $copyFiles = false): int
    {
        throw new ImproperActionException('No duplicate action for resources categories.');
    }

    /**
     * Get an id of an existing one or create it and get its id
     */
    public function getIdempotentIdFromTitle(string $title, ?string $color = null): int
    {
        $sql = 'SELECT id
            FROM items_types WHERE title = :title AND team = :team';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':title', $title);
        $req->bindParam(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $res = $req->fetch(PDO::FETCH_COLUMN);
        if (!is_int($res)) {
            return $this->create(title: $title, color: $color);
        }
        return $res;
    }

    /**
     * Use our own function instead of SortableTrait to add the team param and permission check
     */
    public function updateOrdering(OrderingParams $params): void
    {
        $this->isAdminOrExplode();
        $sql = 'UPDATE items_types SET ordering = :ordering WHERE id = :id AND team = :team';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Users->team, PDO::PARAM_INT);
        foreach ($params->ordering as $ordering => $id) {
            $req->bindParam(':ordering', $ordering, PDO::PARAM_INT);
            $req->bindParam(':id', $id, PDO::PARAM_INT);
            $this->Db->execute($req);
        }
    }

    private function isAdminOrExplode(): void
    {
        if ($this->bypassWritePermission === false && !$this->Users->isAdmin) {
            throw new IllegalActionException('User tried to edit items types but is not Admin');
        }
    }
}
