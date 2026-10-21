
<?php

require_once dirname(__DIR__) . '/config/mongodb.php';

/**
 * Return a MongoDB collection.
 */
function mongoCollection(string $name): MongoDB\Collection
{
    global $mongoDb;

    return $mongoDb->selectCollection($name);
}

/**
 * Find one document using a filter.
 */
function mongoFindOne(
    string $collection,
    array $filter = []
): ?array {
    $document = mongoCollection($collection)->findOne($filter);

    if ($document === null) {
        return null;
    }

    return json_decode(
        MongoDB\BSON\Document::fromPHP($document)->toCanonicalExtendedJSON(),
        true
    );
}

/**
 * Find documents using a filter.
 */
function mongoFind(
    string $collection,
    array $filter = [],
    array $options = []
): array {
    $cursor = mongoCollection($collection)->find($filter, $options);

    $documents = [];

    foreach ($cursor as $document) {
        $documents[] = json_decode(
            MongoDB\BSON\Document::fromPHP($document)->toCanonicalExtendedJSON(),
            true
        );
    }

    return $documents;
}
