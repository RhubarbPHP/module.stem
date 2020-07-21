<?php

/*
 *	Copyright 2015 RhubarbPHP
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 */

namespace Rhubarb\Stem\Repositories\Offline;

require_once __DIR__ . "/../Repository.php";

use Rhubarb\Stem\Collections\RepositoryCollection;
use Rhubarb\Stem\Models\Model;
use Rhubarb\Stem\Repositories\Repository;
use Rhubarb\Stem\Schema\Columns\AutoIncrementColumn;
use Rhubarb\Stem\Schema\Columns\CompositeColumn;

class Offline extends Repository
{
    private $autoNumberCount = 0;

    protected function onObjectSaved(Model $object)
    {
        if ($object->isNewRecord()) {

            $columnName = $object->UniqueIdentifierColumnName;
            if ($object->getSchema()->getColumns()[$columnName] instanceof AutoIncrementColumn) {
                // Assign an auto number as a unique identifier.
                $this->autoNumberCount++;
                $object->setUniqueIdentifier($this->autoNumberCount);
            } else {
                $object->setUniqueIdentifier($object->$columnName);
            }
        }

        /**
         * When 'storing' models with composite columns we try and match the behaviour of database based
         * repositories in that the column values are 'flattened' so that we can filter in unit tests on
         * the sub parts of composite columns.
         */

        $schema = $this->getModelSchema();
        $columns = $schema->getColumns();

        foreach($columns as $column){
            if ($column instanceof CompositeColumn){
                $transform = $column->getTransformIntoRepository();
                $object->mergeRawData($transform($object));
            }
        }

        parent::onObjectSaved($object);
    }

    public function clearObjectCache()
    {
        parent::clearObjectCache();

        $this->autoNumberCount = 0;
    }

    public function clearRepositoryData()
    {
        $this->clearObjectCache();
    }

    public function countRowsInCollection(RepositoryCollection $collection)
    {
        // Force a cursor to be made. This is a weakness in our placement of code. Collection
        // has a method "prepareCursor". This actually executes the queries and afterwards
        // it tries to 'complete' filtering, sorting and aggregating in the event that some
        // of those could not be done in the repository back end store. However the Offline
        // repository relies on that secondary behaviour to work (hence this class is almost
        // empty).
        //
        // It would have been better a) not to support post iteration filtering etc. anyway
        // as it's caused no end of performance problems and b) to have that code in here.
        // 
        // However the existing design is non trivial to modify so for now we simply
        // call offsetExists() which will result in prepareCursor() being called and then
        // we try the count a second time. As a cursor now exists it will not come back
        // so an infinite loop is avoided.
        $collection->offsetExists(0);
        return count($collection);
    }
}
