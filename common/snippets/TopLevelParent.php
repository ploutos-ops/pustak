// Walk up the resource tree to find the top-level parent under site root (id=0).
$id = $modx->resource->get('id');
$parent = $modx->resource->get('parent');

while ($parent != 0) {
    $resource = $modx->getObject('modResource', $parent);
    if (!$resource) break;
    $id = $resource->get('id');
    $parent = $resource->get('parent');
}

return $id;
