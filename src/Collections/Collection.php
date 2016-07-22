<?php

namespace Rhubarb\Stem\Collections;

use Rhubarb\Stem\Aggregates\Aggregate;
use Rhubarb\Stem\Exceptions\BatchUpdateNotPossibleException;
use Rhubarb\Stem\Exceptions\CreatedIntersectionException;
use Rhubarb\Stem\Exceptions\FilterNotSupportedException;
use Rhubarb\Stem\Exceptions\SortNotValidException;
use Rhubarb\Stem\Filters\AndGroup;
use Rhubarb\Stem\Filters\Filter;
use Rhubarb\Stem\Models\Model;
use Rhubarb\Stem\Repositories\PdoRepository;
use Rhubarb\Stem\Schema\Columns\DateColumn;
use Rhubarb\Stem\Schema\Columns\FloatColumn;
use Rhubarb\Stem\Schema\Columns\IntegerColumn;
use Rhubarb\Stem\Schema\Relationships\OneToMany;
use Rhubarb\Stem\Schema\Relationships\OneToOne;
use Rhubarb\Stem\Schema\Relationships\Relationship;
use Rhubarb\Stem\Schema\SolutionSchema;

abstract class Collection implements \ArrayAccess, \Iterator, \Countable
{
    /**
     * The Model class used when spinning out items.
     *
     * @var string
     */
    protected $modelClassName;

    /**
     * The collection of model IDs represented in this collection.
     *
     * @var array
     */
    protected $uniqueIdentifiers = [];

    /**
     * The source of our collection items.
     *
     * @var CollectionCursor
     */
    private $collectionCursor;

    /**
     * The only or top level group filter to apply to the list.
     *
     * @var Filter
     */
    protected $filter;

    /**
     * True if ranging has been disabled
     *
     * @var bool
     */
    private $rangingDisabled = false;

    /**
     * The collection of intersections to perform.
     *
     * @var Intersection[]
     */
    private $intersections = [];

    /**
     * The collection of aggregate columns.
     *
     * @var Aggregate[]
     */
    private $aggregateColumns = [];

    /**
     * An array of sorting directives.
     *
     * @var Sort[]
     */
    private $sorts = [];

    /**
     * An array of column names to group by.
     *
     * @var array
     */
    private $groups = [];

    /**
     * When building complex intersection relationships it's important that a collection can be
     * represented by a unique name (as multiple instances of the collection could be involed)
     *
     * @var string
     * @see getUniqueReference()
     */
    private $uniqueReference;

    private $rangeApplied = false;

    /**
     * Columns that are being added to the collection to serve the purposes of aggregates or intersections
     * are listed here.
     *
     * @var array
     */
    private $aliasedColumns = [];

    /**
     * A map for aliases to the unique reference of their source collection
     *
     * @var array
     */
    private $aliasedColumnsToCollection = [];

    /**
     * Columns that are being added to the collection to serve the purposes of aggregates pulled up from intersections
     * are listed here.
     *
     * @var array
     */
    private $pulledUpAggregatedColumns = [];

    private static $uniqueReferencesUsed = [];

    public function __construct($modelClassName)
    {
        $this->modelClassName = $modelClassName;
    }

    public static function clearUniqueReferencesUsed()
    {
        Collection::$uniqueReferencesUsed = [];
    }

    public function getUniqueReference()
    {
        if ($this->uniqueReference === null){

            $alias = $modelName = basename(str_replace("\\", "/", $this->getModelClassName()));
            $count = 1;

            while (in_array($alias, self::$uniqueReferencesUsed)){
                $count++;
                $alias = $modelName.$count;
            }

            $this->uniqueReference = $alias;
            Collection::$uniqueReferencesUsed[] = $alias;
        }

        return $this->uniqueReference;
    }

    public function getAliasedColumns()
    {
        return $this->aliasedColumns;
    }

    public function getAliasedColumnsToCollection()
    {
        return $this->aliasedColumnsToCollection;
    }

    public function getPulledUpAggregatedColumns()
    {
        return $this->pulledUpAggregatedColumns;
    }

    /**
     * Get's the repository used by the associated data object.
     *
     * @return \Rhubarb\Stem\Repositories\Repository
     */
    public function getRepository()
    {
        $emptyObject = SolutionSchema::getModel($this->modelClassName);

        $repository = $emptyObject->getRepository();

        return $repository;
    }

    public function getModelSchema()
    {
        return $this->getRepository()->getModelSchema();
    }

    public final function addSort($columnName, $ascending = true)
    {
        $parts = explode(".",$columnName);

        if (count($parts) > 1){
            $columnName = $parts[count($parts)-1];

            $relationships = array_slice($parts,0,count($parts)-1);
            $newColumnName = PdoRepository::getPdoParamName($columnName);
            $this->createIntersectionForRelationships($relationships, [$columnName => $newColumnName]);
            $columnName = $newColumnName;
        }

        $sort = new Sort();
        $sort->columnName = $columnName;
        $sort->ascending = $ascending;
        $this->sorts[] = $sort;

        $this->invalidate();

        return $this;
    }

    public final function replaceSort($columnName, $ascending = true)
    {
        if (!is_array($columnName)){
             $sorts = [$columnName => $ascending];
        } else {
            $sorts = $columnName;
        }

        $this->sorts = [];

        foreach($sorts as $index => $value){
            $this->addSort($index, $value);
        }

        return $this;
    }

    /**
     * Append a model to the list and correctly set any fields required to make this re-fetchable through the same list.
     *
     * @param  \Rhubarb\Stem\Models\Model $model
     * @return \Rhubarb\Stem\Models\Model|null
     */
    public function append(Model $model)
    {
        $result = null;
        // If the list was filtered make sure that value is set on the model.
        if ($this->filter !== null) {
            $result = $this->filter->setFilterValuesOnModel($model);
        }
        $model->save();

        // Make sure the list is refetched.
        $this->invalidate();

        return ($result === null) ? $model : $result;
    }

    /**
     * Create an intersection with a second collection.
     *
     * Only rows matching the conditions will remain in the collection.
     *
     * @param Collection $collection
     * @param string $parentColumnName
     * @param string $childColumnName
     * @param string[] $columnsToPullUp An array of column names in the intersected collection to copy to the parent collection.
     * @return $this
     */
    public final function intersectWith(Collection $collection, $parentColumnName, $childColumnName, $columnsToPullUp = [])
    {
        // Group the intersected collection by the child column:
        //$collection->groups[] = $childColumnName;

        $this->intersections[] = new Intersection($collection, $parentColumnName, $childColumnName, $columnsToPullUp);

        $childAggregates = $collection->getAggregateColumns();

        foreach($columnsToPullUp as $column => $alias){

            if (is_numeric($column)){
                $column = $alias;
            }

            $this->aliasedColumns[$alias] = $column;
            $this->aliasedColumnsToCollection[$column] = $collection->getUniqueReference();

            foreach($childAggregates as $aggregate){
                if ($aggregate->getAlias() == $column){
                    $this->pulledUpAggregatedColumns[] = $column;
                }
            }
        }

        $this->invalidate();

        return $this;
    }

    /**
     * Adds the instruction to build an aggregate column.
     *
     * Should be used in conjuction with group()
     *
     * @param Aggregate $aggregate
     * @return $this
     */
    public final function addAggregateColumn(Aggregate $aggregate)
    {
        $parts = explode(".",$aggregate->getAggregateColumnName());

        if (count($parts) > 1){
            $columnName = $parts[count($parts)-1];
            $alias = str_replace(".", "", $aggregate->getAggregateColumnName());

            $relationships = array_slice($parts,0,count($parts)-1);
            $aggregate->setAggregateColumnName($columnName);

            $collection = $this->createIntersectionForRelationships($relationships, [$aggregate->getAlias()]);
            $collection->addAggregateColumn($aggregate);
            return $this;
        }

        $this->aggregateColumns[] = $aggregate;
        $this->invalidate();

        return $this;
    }

    /**
     * @param Aggregate|Aggregate[] $aggregates
     * @return array
     */
    final public function calculateAggregates($aggregates)
    {
        $args = func_get_args();
        if (sizeof($args) > 1) {
            $aggregates = $args;
        }
        if (!is_array($aggregates)) {
            $aggregates = [$aggregates];
        }

        $oldAggregates = $this->aggregateColumns;
        $this->aggregateColumns = [];

        foreach($aggregates as $aggregate) {
            $this->addAggregateColumn($aggregate);
        }

        $this->invalidate();

        $results = [];

        foreach($aggregates as $aggregate){
            $results[] = $this[0][$aggregate->getAlias()];
        }

        $this->aggregateColumns = $oldAggregates;

        $this->invalidate();

        return $results;
    }

    /**
     * Applies the provided set of property values to all of the models in the collection.
     *
     * Where repository specific optimisation is available this will be leveraged to run the batch
     * update at the data source rather than iterating over the items.
     *
     * @param  array $propertyPairs An associative array of key value pairs to update
     * @param  bool $fallBackToIteration If the repository can't perform the action directly, perform the update by iterating over all the models in the collection. You should only pass true if you know that the collection doesn't meet the criteria for an optimised update and the iteration of items won't cause problems
     *                                  iterating over all the models in the collection. You should only pass true
     *                                  if you know that the collection doesn't meet the criteria for an optimised
     *                                  update and the iteration of items won't cause problems
     * @return Collection The original collection returned for chaining
     * @throws BatchUpdateNotPossibleException Thrown if the repository for the collection can't perform the update,
     *                                         and $fallBackToIteration is false.
     */
    public function batchUpdate($propertyPairs, $fallBackToIteration = false)
    {
        try {
            $this->getRepository()->batchCommitUpdatesFromCollection($this, $propertyPairs);
        } catch (BatchUpdateNotPossibleException $er) {
            if ($fallBackToIteration) {
                foreach ($this as $item) {
                    $item->mergeRawData($propertyPairs);
                    $item->save();
                }
            } else {
                throw $er;
            }
        }
        return $this;
    }

    /**
     * Returns the list of aggregate columns.
     *
     * @return \Rhubarb\Stem\Aggregates\Aggregate[]
     */
    public final function getAggregateColumns()
    {
        return $this->aggregateColumns;
    }

    /**
     * Returns the name of the model in our collection.
     *
     * @return string
     */
    final public function getModelClassName()
    {
        return $this->modelClassName;
    }

    /**
     * Replaces the filter on the collection completely rather than blending it as filter() does
     *
     * @param Filter $filter
     * @return $this
     */
    public function replaceFilter(Filter $filter)
    {
        $this->filter = $filter;
        $this->invalidate();

        return $this;
    }

    /**
     * filter the existing list using the supplied DataFilter.
     *
     * @param  Filter $filter
     * @return $this
     */
    public function filter(Filter $filter)
    {
        if (is_null($this->filter)) {
            $this->filter = $filter;
        } else {
            $this->filter = new AndGroup([$filter, $this->filter]);
        }

        $this->invalidate();

        return $this;
    }

    private $rangeStartIndex = 0;
    private $rangeEndIndex = null;

    public function disableRanging()
    {
        $this->rangingDisabled = true;
    }

    public function enableRanging()
    {
        $this->rangingDisabled = false;
    }

    public function getRangeStart()
    {
        return $this->rangeStartIndex;
    }

    public function getRangeEnd()
    {
        return $this->rangeEndIndex;
    }


    /**
     * Limits the range of iteration from startIndex to startIndex + maxItems
     *
     * This can be used by the repository to employ limits but generally allows for easy paging of a list.
     *
     * @param  int $startIndex
     * @param  int $maxItems
     * @return $this
     */
    public function setRange($startIndex, $maxItems)
    {
        $changed = false;
        if (sizeof($this->sorts) == 0) {
            $this->addSort(SolutionSchema::getModelSchema($this->getModelClassName())->uniqueIdentifierColumnName);
        }

        if ($this->rangeStartIndex != $startIndex) {
            $this->rangeStartIndex = $startIndex;
            $changed = true;
        }

        if ($this->rangeEndIndex !== $startIndex + $maxItems - 1) {
            $this->rangeEndIndex = $startIndex + $maxItems - 1;
            $changed = true;
        }

        if ($changed) {
            // Ranges can often be reset to the same values in which case we don't want to invalidate the list
            // as that would cause another query being sent to the database.
            $this->invalidate();
        }

        return $this;
    }

    final public function markRangeApplied()
    {
        $this->rangeApplied = true;
    }

    /**
     * Returns the Filter object being used to filter models for this collection.
     *
     * @return Filter
     */
    final public function getFilter()
    {
        return $this->filter;
    }

    final public function getSorts()
    {
        return $this->sorts;
    }

    final public function getGroups()
    {
        return $this->groups;
    }

    final public function getIntersections()
    {
        return $this->intersections;
    }

    private function invalidate()
    {
        $this->rangeApplied = false;
        $this->collectionCursor = null;
    }

    private function createIntersectionForRelationships($relationshipsNeeded, $pullUps = [])
    {
        $collection = $this;

        foreach($relationshipsNeeded as $relationshipPropertyName){
            $relationships = SolutionSchema::getAllRelationshipsForModel($collection->getModelClassName());

            if (!isset($relationships[$relationshipPropertyName])){
                throw new FilterNotSupportedException("The column couldn't be expanded to intersections");
            }

            $relationship = $relationships[$relationshipPropertyName];

            if ($relationship instanceof OneToMany || $relationship instanceof OneToOne) {
                $targetModel = $relationship->getTargetModelName();
                $parentColumn = $relationship->getSourceColumnName();
                $childColumn = $relationship->getTargetColumnName();

                $collection->intersectWith(
                    $newCollection = new RepositoryCollection($targetModel),
                    $parentColumn,
                    $childColumn,
                    $pullUps
                );

                $collection = $newCollection;
            }
        }

        return $collection;
    }

    /**
     * Ensures a cursor has been created.
     */
    private function prepareCursor()
    {
        if ($this->collectionCursor != null){
            // Cursor already exists, we shouldn't bother making a new one.
            return;
        }

        // If we have intersections we need to protect against getting multiple occurrences of our models
        // in the collection so we add a group by on our unique identifier.

        if (count($this->intersections)>0){
            $uniqueIdentifier = $this->getModelSchema()->uniqueIdentifierColumnName;
            if (!in_array($uniqueIdentifier, $this->groups)){
                $this->groups[] = $uniqueIdentifier;
            }
        }

        // Before we prepare the cursor we should ask all of our filters, sorts and aggregates to
        // check if they have any dot notations that need expanded into intersections.

        $createIntersectionCallback = function($intersectionsNeeded){
            return $this->createIntersectionForRelationships($intersectionsNeeded);
        };

        $filter = $this->getFilter();

        if ($filter){
            try {
                $filter->checkForRelationshipIntersections($this, $createIntersectionCallback);
            } catch (CreatedIntersectionException $ex){
                $this->filter = null;
            }
        }

        $this->collectionCursor = $this->createCursor();

        /**
         * Some cursors will be able to perform intersections. Any intersections remaining
         * are handled by filterIntersections()
         */
        foreach($this->intersections as $intersection){
            if (!$intersection->intersected){
                $this->filterIntersection($intersection);
            }
        }

        /**
         * Some cursors will be able to perform the filtering internally. Those that can't
         * will be handled by filterCursor()
         */
        if (!$this->collectionCursor->filtered){
            $this->filterCursor();
        }

        /**
         * Some cursors can handle aggregates. Any aggregates that aren't yet computed are mopped
         * up in processAggregates();
         */
        $aggregatesToProcess = [];
        foreach($this->aggregateColumns as $aggregateColumn){
            if (!$aggregateColumn->calculated){
                $aggregatesToProcess[] = $aggregateColumn;
            }
        }

        if (count($aggregatesToProcess) > 0){
            $this->processAggregates($aggregatesToProcess);
        }

        /** Some cursors handle sorts. Any that couldn't be handled are processed here */
        $sortsToProcess = [];
        foreach($this->sorts as $sort){
            if (!$sort->sorted){
                $sortsToProcess[] = $sort;
                $this->processSorts($sortsToProcess);
            }
        }

        /**
         * Some cursors can't handle ranging on their own. If we have more rows than we should have we
         * wrap the cursor in the RangeLimitedCursor to supply the required behaviour.
         */

        if (!$this->rangeApplied && ($this->rangeStartIndex > 0 || $this->rangeEndIndex !== null )){
            $augmentationData = $this->collectionCursor->getAugmentationData();
            $this->collectionCursor = new RangeLimitedCursor(
                                                    $this->collectionCursor,
                                                    $this->rangeStartIndex,
                                                    $this->rangeEndIndex);
            $this->collectionCursor->setAugmentationData($augmentationData);
        }

        $this->collectionCursor->deDupe();
        $this->collectionCursor->rewind();
    }

    /**
     * @param Sort[] $sorts
     * @throws SortNotValidException
     */
    private function processSorts($sorts)
    {
        $class = $this->getModelClassName();
        $schema = SolutionSchema::getModelSchema($class);
        $columns = $schema->getColumns();

        $ids = [];
        $arrays = [];
        $directions = [];
        $types = [];

        $this->disableRanging();

        $sorts = $this->getSorts();
        $firstPass = true;

        foreach ($sorts as $sort) {

            $columnName = $sort->columnName;
            $ascending = $sort->ascending;

            $arrays[$columnName] = [];

            $type = SORT_STRING;

            $column = null;

            if (isset($columns[$columnName])) {
                $column = $columns[$columnName];

                if ($column instanceof IntegerColumn || $column instanceof FloatColumn) {
                    $type = SORT_NUMERIC;
                } elseif ($column instanceof DateColumn) {
                    $type = SORT_REGULAR;
                }
            } else {
                $type = SORT_REGULAR;
            }

            $types[$columnName] = $type;
            $directions[$columnName] = ($ascending) ? SORT_ASC : SORT_DESC;

            $totalCount = 0;

            foreach ($this->collectionCursor as $item) {
                if (!isset($item[$columnName])) {
                    throw new SortNotValidException($columnName);
                } else {
                    $itemValue = $item[$columnName];
                }

                $arrays[$columnName][$totalCount] = $itemValue;
                $totalCount++;

                if ($firstPass){
                    $ids[] = $item->getUniqueIdentifier();
                }
            }

            $firstPass = false;
        }

        $this->enableRanging();

        if (sizeof($arrays)) {
            $params = [];

            foreach ($arrays as $column => $data) {
                $params[] = &$arrays[$column];
                $params[] = $directions[$column];
                $params[] = $types[$column];
            }

            $params[] = &$ids;

            call_user_func_array("array_multisort", $params);
        }

        // Switch to the unique identifer list cursor now we have a set list of ids.
        $augmentationData = $this->collectionCursor->getAugmentationData();
        $this->collectionCursor = new UniqueIdentifierListCursor($ids, $class);
        $this->collectionCursor->setAugmentationData($augmentationData);
    }

    private function getGroupKeyForModel(Model $model)
    {
        $key = "";

        foreach($this->groups as $group){
            $key .= $model[$group]."|";
        }

        return $key;
    }

    /**
     * Takes a collection of aggregates and computes their values.
     *
     * @param Aggregate[] $aggregates
     */
    private function processAggregates($aggregates)
    {
        foreach($this->collectionCursor as $model){
            foreach($aggregates as $aggregate){
                $aggregate->calculateByIteration($model, $this->getGroupKeyForModel($model));
            }
        }

        $additionalData = [];

        foreach($aggregates as $aggregate){
            $groups = $aggregate->getGroups();

            foreach($this->collectionCursor as $model){

                $id = $model->UniqueIdentifier;
                $groupKey = $this->getGroupKeyForModel($model);

                if (!isset($additionalData[$id])){
                    $additionalData[$id] = [];
                }

                if (isset($groups[$groupKey])){
                    $additionalData[$id][$aggregate->getAlias()] = $groups[$groupKey];
                }
            }
        }

        $this->collectionCursor->setAugmentationData($additionalData);
    }

    private function filterIntersection(Intersection $intersection)
    {
        $childByIntersectColumn = [];

        foreach($intersection->collection as $childModel){

            $index = $childModel[$intersection->childColumnName];

            if (isset($childByIntersectColumn[$index])){
                if (!is_array($childByIntersectColumn[$index])) {
                    $childByIntersectColumn[$index] = [$childByIntersectColumn[$index]];
                }

                $childByIntersectColumn[$index][] = $childModel;
            } else {
                $childByIntersectColumn[$index] = $childModel;
            }
        }

        $uniqueIdsToFilter = [];
        $augmentationData = [];
        $hasColumnsToPullUp = count($intersection->columnsToPullUp);

        $alreadyDone = [];

        foreach($this->collectionCursor as $parentModel){

            $parentId = rtrim($parentModel->uniqueIdentifier, "_");

            if (isset($alreadyDone[$parentId])){
                continue;
            }

            $alreadyDone[$parentId] = true;

            $parentValue = $parentModel[$intersection->parentColumnName];
            if (!isset($childByIntersectColumn[$parentValue])){
                $uniqueIdsToFilter[] = $parentModel->uniqueIdentifier;
            } else {

                $childRows = $childByIntersectColumn[$parentValue];
                $childRows = (is_array($childRows)) ? $childRows : [$childRows];

                $firstRow = true;
                foreach($childRows as $childRow) {

                    $augmentationIndex = $parentModel->uniqueIdentifier;

                    if (!$firstRow){
                        $augmentationIndex = $this->collectionCursor->duplicateRow($augmentationIndex);
                    }

                    $firstRow = false;

                    if ($hasColumnsToPullUp) {
                        $augmentationData[$augmentationIndex] = [];

                        foreach ($intersection->columnsToPullUp as $column => $alias) {
                            if (is_numeric($column)) {
                                $column = $alias;
                            }
                            $augmentationData[$augmentationIndex][$alias] = $childRow[$column];
                        }
                    }
                }
            }
        }

        if (count($augmentationData)){
            $this->collectionCursor->setAugmentationData($augmentationData);
        }

        $this->collectionCursor->filterModelsByIdentifier($uniqueIdsToFilter);
    }

    /**
     * Filters the collection manually if it wasn't able to filter itself.
     */
    private function filterCursor()
    {
        $filter = $this->getFilter();

        if ($filter){

            $uniqueIdentifiersToFilter = [];

            foreach($this->collectionCursor as $model){
                if ($filter->shouldFilter($model)){
                    $uniqueIdentifiersToFilter[] = $model->uniqueIdentifier;
                }
            }

            $this->collectionCursor->filterModelsByIdentifier($uniqueIdentifiersToFilter);
        }
    }

    protected abstract function createCursor();

    /**
     * Return the current element
     * @link http://php.net/manual/en/iterator.current.php
     * @return mixed Can return any type.
     * @since 5.0.0
     */
    public function current()
    {
        $this->prepareCursor();
        return $this->collectionCursor->current();
    }

    /**
     * Move forward to next element
     * @link http://php.net/manual/en/iterator.next.php
     * @return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function next()
    {
        $this->prepareCursor();
        return $this->collectionCursor->next();
    }

    /**
     * Return the key of the current element
     * @link http://php.net/manual/en/iterator.key.php
     * @return mixed scalar on success, or null on failure.
     * @since 5.0.0
     */
    public function key()
    {
        $this->prepareCursor();
        return $this->collectionCursor->key();
    }

    /**
     * Checks if current position is valid
     * @link http://php.net/manual/en/iterator.valid.php
     * @return boolean The return value will be casted to boolean and then evaluated.
     * Returns true on success or false on failure.
     * @since 5.0.0
     */
    public function valid()
    {
        $this->prepareCursor();
        return $this->collectionCursor->valid();
    }

    /**
     * Rewind the Iterator to the first element
     * @link http://php.net/manual/en/iterator.rewind.php
     * @return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function rewind()
    {
        $this->prepareCursor();
        $this->collectionCursor->rewind();
    }

    /**
     * Whether a offset exists
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset <p>
     * An offset to check for.
     * </p>
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     * @since 5.0.0
     */
    public function offsetExists($offset)
    {
        $this->prepareCursor();
        return $this->collectionCursor->offsetExists($offset);
    }

    /**
     * Offset to retrieve
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset <p>
     * The offset to retrieve.
     * </p>
     * @return mixed Can return all value types.
     * @since 5.0.0
     */
    public function offsetGet($offset)
    {
        $this->prepareCursor();
        return $this->collectionCursor->offsetGet($offset);
    }

    /**
     * Offset to set
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset <p>
     * The offset to assign the value to.
     * </p>
     * @param mixed $value <p>
     * The value to set.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetSet($offset, $value)
    {
        $this->prepareCursor();
        $this->collectionCursor->offsetSet($offset, $value);
    }

    /**
     * Offset to unset
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset <p>
     * The offset to unset.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetUnset($offset)
    {
        $this->prepareCursor();
        $this->collectionCursor->offsetUnset($offset);
    }

    /**
     * Count elements of an object
     * @link http://php.net/manual/en/countable.count.php
     * @return int The custom count as an integer.
     * </p>
     * <p>
     * The return value is cast to an integer.
     * @since 5.1.0
     */
    public function count()
    {
        $this->prepareCursor();
        return $this->collectionCursor->count();
    }

    public function toArray()
    {
        $array = [];

        foreach($this as $model){
            $array[] = $model;
        }

        return $array;
    }
}