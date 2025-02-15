<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2024 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Elabftw\Compound;
use Elabftw\Elabftw\Permissions;
use Elabftw\Elabftw\Tools;
use Elabftw\Enums\AccessType;
use Elabftw\Enums\Action;
use Elabftw\Enums\BasePermissions;
use Elabftw\Enums\Orderby;
use Elabftw\Enums\State;
use Elabftw\Exceptions\IllegalActionException;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Interfaces\QueryParamsInterface;
use Elabftw\Params\BaseQueryParams;
use Elabftw\Params\CompoundParams;
use Elabftw\Services\Fingerprinter;
use Elabftw\Services\HttpGetter;
use Elabftw\Services\PubChemImporter;
use Elabftw\Traits\SetIdTrait;
use Override;
use PDO;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Compounds are chemical entities stored in the `compounds` SQL table
 */
class Compounds extends AbstractRest
{
    use SetIdTrait;

    public function __construct(protected HttpGetter $httpGetter, private Users $requester, ?int $id = null)
    {
        parent::__construct();
        $this->setId($id);
    }

    public function getApiPath(): string
    {
        return 'api/v2/compounds/';
    }

    #[Override]
    public function readAll(?QueryParamsInterface $queryParams = null): array
    {
        $queryParams ??= $this->getQueryParams();
        if (!empty($queryParams->getQuery()->get('search_pubchem_cid'))) {
            return $this->searchPubChem($queryParams->getQuery()->getInt('search_pubchem_cid'))->toArray();
        }
        if (!empty($queryParams->getQuery()->get('search_pubchem_cas'))) {
            return $this->searchPubChemCas($queryParams->getQuery()->getString('search_pubchem_cas'))->toArray();
        }
        if (!empty($queryParams->getQuery()->get('search_fp_smi'))) {
            $q = $queryParams->getQuery();
            return $this->searchFingerprint($this->getFingerprintFromSmiles($q->getString('search_fp_smi')), $q->getBoolean('exact'));
        }
        $sql = $this->getSelectBeforeWhere() . ' WHERE 1=1';
        if ($queryParams->getQuery()->get('q')) {
            $sql .= ' AND (
                entity.cas_number LIKE :query OR
                entity.ec_number LIKE :query OR
                entity.chebi_id LIKE :query OR
                entity.chembl_id LIKE :query OR
                entity.dea_number LIKE :query OR
                entity.drugbank_id LIKE :query OR
                entity.dsstox_id LIKE :query OR
                entity.hmdb_id LIKE :query OR
                entity.inchi LIKE :query OR
                entity.inchi_key LIKE :query OR
                entity.iupac_name LIKE :query OR
                entity.kegg_id LIKE :query OR
                entity.metabolomics_wb_id LIKE :query OR
                entity.molecular_formula LIKE :query OR
                entity.molecular_weight LIKE :query OR
                entity.name LIKE :query OR
                entity.nci_code LIKE :query OR
                entity.nikkaji_number LIKE :query OR
                entity.pharmgkb_id LIKE :query OR
                entity.pharos_ligand_id LIKE :query OR
                entity.pubchem_cid LIKE :query OR
                entity.rxcui LIKE :query OR
                entity.smiles LIKE :query OR
                entity.unii LIKE :query OR
                entity.wikidata LIKE :query OR
                entity.wikipedia LIKE :query
            )';
        }
        $sql .= $queryParams->getSql();
        $req = $this->Db->prepare($sql);
        // FIXME: dumb but working fix: user throws error, admin/sysadmin doesn't because sql query might have :userid or not
        if (str_contains($sql, ':userid')) {
            $req->bindParam(':userid', $this->requester->userid, PDO::PARAM_INT);
        }
        if ($queryParams->getQuery()->get('q')) {
            $req->bindValue(':query', '%' . $queryParams->getQuery()->getString('q') . '%');
        }
        $this->Db->execute($req);

        return $req->fetchAll();

    }

    #[Override]
    public function getQueryParams(?InputBag $query = null): QueryParamsInterface
    {
        return new BaseQueryParams(query: $query, orderby: Orderby::Lastchange);
    }

    #[Override]
    public function readOne(): array
    {
        $sql = $this->getSelectBeforeWhere() . ' WHERE entity.id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $this->Db->fetch($req);
    }

    #[Override]
    public function patch(Action $action, array $params): array
    {
        $this->canOrExplode(AccessType::Write);
        foreach ($params as $target => $content) {
            $this->update(new CompoundParams($target, $content));
        }
        return $this->readOne();
    }

    public function update(CompoundParams $params): bool
    {
        $sql = sprintf('UPDATE compounds SET %s = :content, modified_by = :requester WHERE id = :id', $params->getColumn());
        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        $req->bindValue(':content', $params->getContent());
        $req->bindParam(':requester', $this->requester->userid, PDO::PARAM_INT);

        return $this->Db->execute($req);
    }

    #[Override]
    public function postAction(Action $action, array $reqBody): int
    {
        // TODO add action fromCid or fromSmiles
        // and use fingerprinter
        return match ($action) {
            Action::Duplicate => $this->createCompoundFromIdentifier($reqBody),
            default => $this->create(
                name: $reqBody['name'] ?? null,
                inchi: $reqBody['inchi'] ?? null,
                inchiKey: $reqBody['inchi_key'] ?? null,
                smiles: $reqBody['smiles'] ?? null,
                molecularFormula: $reqBody['molecular_formula'] ?? null,
                casNumber: $reqBody['cas_number'] ?? null,
                iupacName: $reqBody['iupac_name'] ?? null,
                pubchemCid: $reqBody['pubchem_cid'] ?? null,
                isCorrosive: (bool) ($reqBody['is_corrosive'] ?? false),
                isExplosive: (bool) ($reqBody['is_explosive'] ?? false),
                isFlammable: (bool) ($reqBody['is_flammable'] ?? false),
                isGasUnderPressure: (bool) ($reqBody['is_gas_under_pressure'] ?? false),
                isHazardous2env: (bool) ($reqBody['is_hazardous2env'] ?? false),
                isHazardous2health: (bool) ($reqBody['is_hazardous2health'] ?? false),
                isOxidising: (bool) ($reqBody['is_oxidising'] ?? false),
                isToxic: (bool) ($reqBody['is_toxic'] ?? false),
                withFingerprint: Config::boolFromEnv('USE_FINGERPRINTER'),
            ),
        };
    }

    #[Override]
    public function destroy(): bool
    {
        return $this->update(new CompoundParams('state', State::Deleted->value));
    }

    public function create(
        ?string $inchi = null,
        ?string $inchiKey = null,
        ?string $name = null,
        ?string $smiles = null,
        ?string $molecularFormula = null,
        ?float $molecularWeight = 0.00,
        ?string $casNumber = null,
        ?string $iupacName = null,
        ?int $pubchemCid = null,
        bool $isCorrosive = false,
        bool $isExplosive = false,
        bool $isFlammable = false,
        bool $isGasUnderPressure = false,
        bool $isHazardous2env = false,
        bool $isHazardous2health = false,
        bool $isOxidising = false,
        bool $isToxic = false,
        bool $withFingerprint = true,
    ): int {

        $sql = 'INSERT INTO compounds (
            created_by, modified_by, name,
            inchi, inchi_key,
            smiles, molecular_formula, molecular_weight,
            cas_number, iupac_name, pubchem_cid, userid, team,
            is_corrosive, is_explosive, is_flammable, is_gas_under_pressure, is_hazardous2env, is_hazardous2health, is_oxidising, is_toxic
            ) VALUES (
            :requester, :requester, :name,
            :inchi, :inchi_key,
            :smiles, :molecular_formula, :molecular_weight,
            :cas_number, :iupac_name, :pubchem_cid, :requester, :team,
            :is_corrosive, :is_explosive, :is_flammable, :is_gas_under_pressure, :is_hazardous2env, :is_hazardous2health, :is_oxidising, :is_toxic)';

        $req = $this->Db->prepare($sql);
        $req->bindParam(':requester', $this->requester->userid);
        $req->bindParam(':team', $this->requester->team);
        $req->bindParam(':name', $name);
        $req->bindParam(':inchi', $inchi);
        $req->bindParam(':inchi_key', $inchiKey);
        $req->bindParam(':smiles', $smiles);
        $req->bindParam(':molecular_formula', $molecularFormula);
        $req->bindParam(':molecular_weight', $molecularWeight);
        $req->bindParam(':cas_number', $casNumber);
        $req->bindParam(':iupac_name', $iupacName);
        $req->bindParam(':pubchem_cid', $pubchemCid);
        $req->bindParam(':is_corrosive', $isCorrosive, PDO::PARAM_INT);
        $req->bindParam(':is_explosive', $isExplosive, PDO::PARAM_INT);
        $req->bindParam(':is_flammable', $isFlammable, PDO::PARAM_INT);
        $req->bindParam(':is_gas_under_pressure', $isGasUnderPressure, PDO::PARAM_INT);
        $req->bindParam(':is_hazardous2env', $isHazardous2env, PDO::PARAM_INT);
        $req->bindParam(':is_hazardous2health', $isHazardous2health, PDO::PARAM_INT);
        $req->bindParam(':is_oxidising', $isOxidising, PDO::PARAM_INT);
        $req->bindParam(':is_toxic', $isToxic, PDO::PARAM_INT);

        $this->Db->execute($req);

        $compoundId = $this->Db->lastInsertId();

        if ($withFingerprint && !empty($smiles)) {
            $this->fingerprintCompound($smiles, $compoundId);
        }
        return $compoundId;
    }

    protected function canOrExplode(AccessType $accessType): bool
    {
        if ($this->id === null) {
            throw new ImproperActionException('No id is set!');
        }
        $sql = 'SELECT team, userid FROM compounds WHERE id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        $this->Db->execute($req);
        $compound = $req->fetch();
        $compound['canread'] = BasePermissions::Organization->toJson();
        $compound['canwrite'] = BasePermissions::Team->toJson();
        $Permissions = new Permissions($this->requester, $compound);
        $perms = $Permissions->forEntity();
        return $perms[str_replace('can', '', $accessType->value)] || throw new IllegalActionException(Tools::error(true));
    }

    private function createCompoundFromIdentifier(array $reqBody): int
    {
        if (!empty($reqBody['cid'])) {
            return $this->createFromCompound($this->searchPubChem((int) $reqBody['cid']));
        }
        return $this->createFromCompound($this->searchPubChemCas($reqBody['cas']));
    }

    private function getSelectBeforeWhere(): string
    {
        return 'SELECT entity.*,
            CONCAT(
                TO_BASE64(fp0), TO_BASE64(fp1), TO_BASE64(fp2), TO_BASE64(fp3),
                TO_BASE64(fp4), TO_BASE64(fp5), TO_BASE64(fp6), TO_BASE64(fp7),
                TO_BASE64(fp8), TO_BASE64(fp9), TO_BASE64(fp10), TO_BASE64(fp11),
                TO_BASE64(fp12), TO_BASE64(fp13), TO_BASE64(fp14), TO_BASE64(fp15),
                TO_BASE64(fp16), TO_BASE64(fp17), TO_BASE64(fp18), TO_BASE64(fp19),
                TO_BASE64(fp20), TO_BASE64(fp21), TO_BASE64(fp22), TO_BASE64(fp23),
                TO_BASE64(fp24), TO_BASE64(fp25), TO_BASE64(fp26), TO_BASE64(fp27),
                TO_BASE64(fp28), TO_BASE64(fp29), TO_BASE64(fp30), TO_BASE64(fp31)
            ) AS fp2_base64,
            CONCAT(users.firstname, " ", users.lastname) AS userid_human,
            teams.name AS team_name,
            CASE WHEN compounds_fingerprints.id IS NOT NULL THEN 1 ELSE 0 END AS has_fingerprint
            FROM compounds AS entity
            LEFT JOIN compounds_fingerprints ON (compounds_fingerprints.id = entity.id)
            LEFT JOIN teams ON (entity.team = teams.id)
            LEFT JOIN users ON (users.userid = entity.userid)';
    }

    private function searchFingerprint(array $fp, bool $exact = false): array
    {
        // if all values of FP are 0, we cannot check for it, so return early
        if (array_sum($fp['data']) === 0) {
            return array();
        }
        $sql = 'SELECT cf.id, c.name, c.cas_number, c.smiles, c.inchi, c.inchi_key, c.iupac_name, c.molecular_formula, c.pubchem_cid,
            (BIT_COUNT(';

        // Calculate A ∩ B (bitwise AND) and A + B (bitwise OR) in SQL
        foreach ($fp['data'] as $key => $value) {
            if ($value == 0) {
                continue;
            }
            $sql .= sprintf('(fp%d & %d) | ', $key, $value);
        }
        $sql = rtrim($sql, '| ') . ')) AS similarity_score ';

        $sql .= 'FROM compounds_fingerprints AS cf
            LEFT JOIN compounds AS c ON cf.id = c.id';
        $sql .= ' WHERE 1=1';
        foreach ($fp['data'] as $key => $value) {
            if ($value == 0) {
                continue;
            }
            // this will do an approximate substructure search
            $exactModifier = sprintf(' & %d ', $value);
            if ($exact) {
                $exactModifier = '';
            }
            $sql .= sprintf(' AND fp%d %s = %d', $key, $exactModifier, $value);
        }

        $sql .= ' ORDER BY similarity_score, id DESC LIMIT 500';
        $req = $this->Db->prepare($sql);
        $req->execute();
        return $req->fetchAll();
    }

    private function searchPubChem(int $cid): Compound
    {
        $Importer = new PubChemImporter($this->httpGetter);
        return $Importer->fromPugView($cid);
    }

    private function searchPubChemCas(string $cas): Compound
    {
        $Importer = new PubChemImporter($this->httpGetter);
        $cid = $Importer->getCidFromCas($cas);
        return $this->searchPubChem($cid);
    }

    private function getFingerprintFromSmiles(string $smiles): array
    {
        $Fingerprinter = new Fingerprinter($this->httpGetter, Config::boolFromEnv('USE_FINGERPRINTER'));
        return $Fingerprinter->calculate('smi', $smiles);
    }

    private function fingerprintCompound(string $smiles, int $compoundId): int
    {
        $fp = $this->getFingerprintFromSmiles($smiles);
        // if fingerprinter is not configured, no data will exist in the response
        $Fingerprints = new Fingerprints($compoundId);
        return $Fingerprints->create($fp['data']);
    }

    private function createFromCompound(Compound $compound): int
    {
        return $this->create(
            casNumber: $compound->cas,
            name: $compound->name,
            inchi: $compound->inChI,
            inchiKey: $compound->inChIKey,
            smiles: $compound->smiles,
            iupacName: $compound->iupacName,
            pubchemCid: $compound->cid,
            molecularFormula: $compound->molecularFormula,
            molecularWeight: $compound->molecularWeight,
            isCorrosive: $compound->isCorrosive,
            isExplosive: $compound->isExplosive,
            isFlammable: $compound->isFlammable,
            isGasUnderPressure: $compound->isGasUnderPressure,
            isHazardous2env: $compound->isHazardous2env,
            isHazardous2health: $compound->isHazardous2health,
            isOxidising: $compound->isOxidising,
            isToxic: $compound->isToxic,
            withFingerprint: Config::boolFromEnv('USE_FINGERPRINTER'),
        );
    }
}
