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
use Elabftw\Elabftw\Metadata;
use Elabftw\Elabftw\Tools;
use Elabftw\Enums\Action;
use Elabftw\Enums\BasePermissions;
use Elabftw\Enums\EntityType;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Services\Filter;
use Elabftw\Traits\InsertTagsTrait;
use PDO;

/**
 * All about the experiments
 */
class Experiments extends AbstractConcreteEntity
{
    use InsertTagsTrait;

    public EntityType $entityType = EntityType::Experiments;

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
    ): int {
        $canread ??= BasePermissions::Team->toJson();
        $canwrite ??= BasePermissions::User->toJson();
        $Templates = new Templates($this->Users);

        // defaults
        $title = Filter::title($title ?? _('Untitled'));
        $date ??= new DateTimeImmutable();
        $body = Filter::body($body);
        if (empty($body)) {
            $body = null;
        }
        $metadata = null;
        $contentType = AbstractEntity::CONTENT_HTML;
        if ($this->Users->userData['use_markdown'] ?? 0) {
            $contentType = AbstractEntity::CONTENT_MD;
        }

        // do we want template ?
        // $templateId can be a template id, or 0: common template, or -1: null body
        if ($template > 0) {
            $Templates->setId($template);
            $templateArr = $Templates->readOne();
            $title = $templateArr['title'];
            $category = $templateArr['category'];
            $status = $templateArr['status'];
            $body = $templateArr['body'];
            $canread = $templateArr['canread_target'];
            $canwrite = $templateArr['canwrite_target'];
            $metadata = $templateArr['metadata'];
            $contentType = (int) $templateArr['content_type'];
        }

        // we don't use a proper template (use of common tpl or blank)
        if ($template === 0 || $template === -1) {
            // if admin forced template use, throw error
            if ($forceExpTpl) {
                throw new ImproperActionException(_('Experiments must use a template!'));
            }
            // use user settings for permissions
            $canread = $this->Users->userData['default_read'] ?? BasePermissions::Team->toJson();
            $canwrite = $this->Users->userData['default_write'] ?? BasePermissions::User->toJson();
        }
        // load common template
        if ($template === 0) {
            $body = $defaultTemplateHtml;
            // use the markdown template if the user prefers markdown
            if ($this->Users->userData['use_markdown']) {
                $body = $defaultTemplateMd;
            }
        }

        // figure out the custom id
        $customId = $this->getNextCustomId($template);

        // SQL for create experiments
        $sql = 'INSERT INTO experiments(team, title, date, body, category, status, elabid, canread, canwrite, metadata, custom_id, userid, content_type, rating)
            VALUES(:team, :title, :date, :body, :category, :status, :elabid, :canread, :canwrite, :metadata, :custom_id, :userid, :content_type, :rating)';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindParam(':title', $title);
        $req->bindValue(':date', $date->format('Y-m-d'));
        $req->bindParam(':body', $body);
        $req->bindValue(':category', $category);
        $req->bindValue(':status', $status);
        $req->bindValue(':elabid', Tools::generateElabid());
        $req->bindParam(':canread', $canread);
        $req->bindParam(':canwrite', $canwrite);
        $req->bindParam(':metadata', $metadata);
        $req->bindParam(':custom_id', $customId, PDO::PARAM_INT);
        $req->bindParam(':userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        $req->bindParam(':content_type', $contentType, PDO::PARAM_INT);
        $req->bindParam(':rating', $rating, PDO::PARAM_INT);
        $this->Db->execute($req);
        $newId = $this->Db->lastInsertId();
        $this->setId($newId);

        // insert the tags, steps and links from the template
        if ($template > 0) {
            $Tags = new Tags($Templates);
            $Tags->copyTags($newId, true);
            $this->Steps->duplicate($template, $newId, true);
            $this->ItemsLinks->duplicate($template, $newId, true);
            $this->ExperimentsLinks->duplicate($template, $newId, true);
            $Templates->Uploads->duplicate($this);
        }

        $this->insertTags($tags, $newId);

        return $newId;
    }

    /**
     * Duplicate an experiment
     *
     * @return int the ID of the new item
     */
    public function duplicate(bool $copyFiles = false): int
    {
        $this->canOrExplode('read');

        // let's add something at the end of the title to show it's a duplicate
        // capital i looks good enough
        $title = $this->entityData['title'] . ' I';

        $Teams = new Teams($this->Users);
        $Status = new ExperimentsStatus($Teams);

        // handle the blank_value_on_duplicate attribute on extra fields
        $metadata = (new Metadata($this->entityData['metadata']))->blankExtraFieldsValueOnDuplicate();
        // figure out the custom id
        $customId = $this->getNextCustomId($this->entityData['category']);

        $sql = 'INSERT INTO experiments(team, title, date, body, category, status, elabid, canread, canwrite, userid, metadata, custom_id, content_type)
            VALUES(:team, :title, CURDATE(), :body, :category, :status, :elabid, :canread, :canwrite, :userid, :metadata, :custom_id, :content_type)';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindParam(':title', $title);
        $req->bindParam(':body', $this->entityData['body']);
        $req->bindValue(':category', $this->entityData['category']);
        $req->bindValue(':status', $Status->getDefault(), PDO::PARAM_INT);
        $req->bindValue(':elabid', Tools::generateElabid());
        $req->bindParam(':canread', $this->entityData['canread']);
        $req->bindParam(':canwrite', $this->entityData['canwrite']);
        $req->bindParam(':metadata', $metadata);
        $req->bindParam(':custom_id', $customId, PDO::PARAM_INT);
        $req->bindParam(':userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        $req->bindParam(':content_type', $this->entityData['content_type'], PDO::PARAM_INT);
        $this->Db->execute($req);
        $newId = $this->Db->lastInsertId();
        $fresh = new self($this->Users, $newId);
        /** @psalm-suppress PossiblyNullArgument
         * this->id cannot be null here, checked during canOrExplode */
        $this->ExperimentsLinks->duplicate($this->id, $newId);
        $this->ItemsLinks->duplicate($this->id, $newId);
        $this->Steps->duplicate($this->id, $newId);
        $this->Tags->copyTags($newId);
        // also add a link to the previous experiment
        $ExperimentsLinks = new Experiments2ExperimentsLinks($fresh);
        $ExperimentsLinks->setId($this->id);
        $ExperimentsLinks->postAction(Action::Create, array());
        if ($copyFiles) {
            $this->Uploads->duplicate($fresh);
        }

        return $newId;
    }

    /**
     * Experiment is not actually deleted but the state is changed from normal to deleted
     */
    public function destroy(): bool
    {
        // delete from pinned too
        return parent::destroy() && $this->Pins->cleanup();
    }
}
