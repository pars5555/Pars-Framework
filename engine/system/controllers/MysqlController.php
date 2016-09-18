<?php

namespace system\controllers {

    abstract class MysqlController {

        protected $connector;
        private $lastSelectAdvanceWhere = null;

        /**
         * Initializes DBMS pointer.
         */
        function __construct() {
            $mysqlConfig = Sys()->getConfig('mysql');
            if (!isset($mysqlConfig)) {
                \system\SysExceptions::noMysqlConfig();
            }

            $this->connector = \system\connectors\MySql::getInstance($mysqlConfig['host'], $mysqlConfig['user'], $mysqlConfig['pass'], $mysqlConfig['name']);
        }

        abstract public function getTableName();

        public function createRecordInstance() {
            return new \stdClass();
        }

        /**
         * Inserts dto into table.
         *
         * @param object $object
         * @param object $esc [optional] - shows if the textual values must be escaped before setting to DB
         * @return autogenerated id or -1 if something goes wrong
         */
        public function insert($object) {
            //validating input params
            if ($object == null) {
                \system\SysExceptions::unknownError();
            }
            $fieldsNameValue = get_object_vars($object);
            //creating query
            $sqlQuery = sprintf("INSERT INTO `%s` SET ", $this->getTableName());
            foreach ($fieldsNameValue as $fieldName => $fieldValue) {
                $sqlQuery .= sprintf(" `%s` = :%s,", $fieldName, $fieldValue);
            }
            $res = $this->connector->prepare(trim($sqlQuery, ','));
            if ($res) {
                $res->execute($fieldsNameValue);
                return $this->connector->lastInsertId();
            }
            return null;
        }

        public function updateFieldById($id, $fieldName, $fieldValue) {
            if (isset($fieldValue)) {
                $sqlQuery = sprintf("UPDATE `%s` SET `%s` = :%s WHERE `id` = :id", $this->getTableName(), $fieldName, $fieldName);
            } else {
                $sqlQuery = sprintf("UPDATE `%s` SET `%s` = NULL WHERE `%s` = :id ", $this->getTableName(), $fieldName);
            }
            $res = $this->connector->prepare($sqlQuery);
            if ($res) {
                $res->execute(array("id" => $id, $fieldName => $fieldValue));
                return $res->rowCount();
            }
            return null;
        }

        /**
         * Deletes the row by primary key
         *
         * @param object $id - the unique identifier of table
         * @return affacted rows count or -1 if something goes wrong
         */
        public function deleteByPK($id) {

            $sqlQuery = sprintf("DELETE FROM `%s` WHERE `%s` = :id", $this->getTableName(), $this->getPKFieldName());
            $res = $this->connector->prepare($sqlQuery);
            if ($res) {
                $res->execute(array("id" => $id));
                return $res->rowCount();
            }
            return null;
        }

        public function fetchAll($sqlQuery, $params = array()) {
            $res = $this->connector->prepare($sqlQuery);
            $results = $res->execute($params);
            if ($results == false) {
                return false;
            }
            $resultArr = [];
            while ($row = $res->fetchObject(get_class($this->createRecordInstance()))) {
                $resultArr[] = $row;
            }
            return $resultArr;
        }

        /**
         * Executes the query and returns an row field of corresponding DTOs
         * if $row isn't false return first elem
         *
         * @param object $sqlQuery
         * @return
         */
        public function fetchOne($sqlQuery, $params = array(), $standartArgs = false) {
            $rows = $this->fetchAll($sqlQuery, $params, $standartArgs);
            if (!empty($rows) && is_array($rows)) {
                return $rows[0];
            }
            return false;
        }

        public function fetchField($sqlQuery, $fieldName, $params = array()) {
            // Execute query.
            $res = $this->connector->prepare($sqlQuery);
            $results = $res->execute($params);
            if ($results) {
                return $res->fetchObject()->$fieldName;
            }
            return null;
        }

        /**
         * Selects all entries from table
         * @return
         */
        public function selectAll() {
            $sqlQuery = sprintf("SELECT * FROM `%s`", $this->getTableName());
            return $this->fetchAll($sqlQuery);
        }

        /**
         * Selects from table by primary key and returns corresponding DTO
         *
         * @param object $id
         * @return
         */
        public function selectById($id) {
            $sqlQuery = sprintf("SELECT * FROM `%s` WHERE `id` = :id ", $this->getTableName());
            return $this->fetchOne($sqlQuery, ["id" => $id]);
        }

        public function selectByIds($ids) {
            $sqlQuery = sprintf("SELECT * FROM `%s` WHERE `id` in (%s) ", $this->getTableName(), implode(',', $ids));
            return $this->fetchAll($sqlQuery);
        }

        public function countAdvance($filters = null) {
            $where = $this->getWhereSubQueryByFilters($filters);
            $sqlQuery = sprintf("SELECT count(id) as `count` FROM `%s` %s ", $this->getTableName(), $where);
            return intval($this->fetchField($sqlQuery, 'count'));
        }

        public function selectAdvanceOne($filters = []) {
            $objects = $this->selectAdvance('*',$filters);
            if (!empty($objects)) {
                return $objects[0];
            }
            return false;
        }

        public function selectAdvance($fieldsArray = '*', $filters = [], $groupByFieldsArray = [], $orderByFieldsArray = [], $orderByAscDesc = "ASC", $offset = null, $limit = null, $mapByField = False) {
            $where = $this->getWhereSubQueryByFilters($filters);
            $groupBy = $this->getGroupBySubQueryByFilters($groupByFieldsArray);
            $fields = $this->getFieldsSubQuery($fieldsArray);

            $order = "";
            if (!in_array(strtoupper($orderByAscDesc), ['ASC', 'DESC'])) {
                $orderByAscDesc = 'ASC';
            }

            if (!empty($orderByFieldsArray)) {
                $order = $orderByFieldsArray;
                if (is_array($orderByFieldsArray)) {
                    $order = implode('`, `', $orderByFieldsArray);
                }
                $order = 'ORDER BY `' . $order . '` ' . $orderByAscDesc;
            }
            $this->lastSelectAdvanceWhere = $where;
            $sqlQuery = sprintf("SELECT %s FROM `%s` %s %s %s ", $fields, $this->getTableName(), $where, $groupBy, $order);
            if (isset($limit) && $limit > 0) {
                $sqlQuery .= ' LIMIT ' . $offset . ', ' . $limit;
            }
            $ret = $this->fetchAll($sqlQuery);
            if ($mapByField) {
                return $this->mapObjectsByField($ret, $mapByField);
            }
            return $ret;
        }

        public function updateAdvance($where, $fieldsValuesMapArray) {
            $where = $this->getWhereSubQueryByFilters($where);
            $subQuerySetValues = "";
            foreach ($fieldsValuesMapArray as $fieldName => $fieldValue) {
                $subQuerySetValues .= "`$fieldName` = :$fieldName ,";
            }
            $subQuerySetValues = trim($subQuerySetValues);
            $subQuerySetValues = trim($subQuerySetValues, ',');

            $sqlQuery = sprintf("UPDATE `%s` SET %s %s", $this->getTableName(), $subQuerySetValues, $where);
            $res = $this->dbms->prepare($sqlQuery);
            if ($res) {
                $res->execute($fieldsValuesMapArray);
                return $res->rowCount($fieldsValuesMapArray);
            }
            return null;
        }

        public function deleteAdvance($where) {
            $where = $this->getWhereSubQueryByFilters($where);
            $sqlQuery = sprintf("DELETE FROM `%s` %s", $this->getTableName(), $where);
            $res = $this->dbms->prepare($sqlQuery);
            if ($res) {
                $res->execute();
                return $res->rowCount();
            }
            return null;
        }

        public function getLastSelectAdvanceRowsCount() {
            if (!isset($this->lastSelectAdvanceWhere)) {
                return 0;
            }
            return intval($this->selectAdvanceCount($this->lastSelectAdvanceWhere));
        }

        public function selectByField($fieldName, $fieldValue) {
            $sqlQuery = sprintf("SELECT * FROM `%s` WHERE `%s` = :value ", $this->getTableName(), $fieldName);
            return $this->fetchAll($sqlQuery, array("value" => $fieldValue));
        }

        public function deleteByField($fieldName, $fieldValue) {
            $sqlQuery = sprintf("DELETE FROM `%s` WHERE `%s` = :value ", $this->getTableName(), $fieldName);
            $res = $this->dbms->prepare($sqlQuery);
            if ($res) {
                $res->execute(array("value" => $fieldValue));
                return $res->rowCount();
            }
            return null;
        }

        public function startTransaction() {
            $this->dbms->beginTransaction();
        }

        /**
         * Commits the current transaction
         */
        public function commitTransaction() {
            $this->dbms->commit();
        }

        /**
         * Rollback the current transaction
         */
        public function rollbackTransaction() {
            $this->dbms->rollback();
        }

        private function getWhereSubQueryByFilters($filters) {
            if (empty($filters)) {
                return "";
            }
            $where = "WHERE ";
            foreach ($filters as $filter) {
                $strToLoqerFilter = strtolower($filter);
                if (in_array($strToLoqerFilter, [')', '(', 'and', 'or', '<', '<=', '=', '>', '>=', 'is', 'null', 'not'])) {
                    $where .= ' ' . strtoupper($strToLoqerFilter) . ' ';
                } else {
                    $where .= ' ' . $filter . ' ';
                }
            }
            return $where;
        }

        private function getFieldsSubQuery($fieldsArray) {
            if (empty($fieldsArray) || $fieldsArray === '*') {
                return "*";
            }
            if (!is_array($fieldsArray)) {
                $fieldsArray = [$fieldsArray];
            }
            $ret = "";
            foreach ($fieldsArray as $fieldName) {
                if (strpos($fieldName, '`') === false) {
                    $fieldName = '`' . $fieldName . '`';
                }
                $ret .= $fieldName . ',';
            }
            return trim($ret, ',');
        }

        private function getGroupBySubQueryByFilters($groupByFieldsArray) {
            if (empty($groupByFieldsArray)) {
                return "";
            }
            $ret = 'GROUP BY ';
            if (!is_array($groupByFieldsArray)) {
                $groupByFieldsArray = [$groupByFieldsArray];
            }

            foreach ($groupByFieldsArray as $fieldName) {
                if (strpos($fieldName, '`') === false) {
                    $fieldName = '`' . $fieldName . '`';
                }
                $ret .= $fieldName . ',';
            }
            return trim($ret, ',');
        }

        private function mapObjectsByField($dtos, $fieldName) {
            $mappedDtos = array();
            foreach ($dtos as $dto) {
                $mappedDtos[$dto->$fieldName] = $dto;
            }
            return $mappedDtos;
        }

    }

}
