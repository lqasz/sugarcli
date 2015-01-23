<?php

namespace SugarCli\Sugar;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Manage fields_meta_data table.
 */
class Metadata extends Sugar
{
    const TABLE_NAME = 'fields_meta_data';

    const ADD = 0;
    const DEL = 1;
    const UPDATE = 2;

    const BASE = 0;
    const MODIFIED = 1;

    /**
     * Path of metadata definition file.
     */
    protected $metadata_file;

    public function __construct($path = null, LoggerInterface $logger = null, $metadata_file = null)
    {
        parent::__construct($path, $logger);
        $this->metadata_file = $metadata_file;
    }

    public function setMetadataFile($metadata_file)
    {
        $this->metadata_file = $metadata_file;
        return $this;
    }

    /**
     * Fetch metadata array from the sugar database.
     */
    public function getFromDb()
    {
        $this->logger->debug('Reading fields_meta_data from DB.');
        $sql = 'SELECT * FROM ' . self::TABLE_NAME;
        $res = $this->getExternalDb()->query($sql);
        $fields = array();
        foreach ($res->fetchAll() as $row) {
            $fields[$row['id']] = $row;
        }
        ksort($fields);
        return $fields;
    }

    /**
     * Fetch metadata array from the definition file
     */
    public function getFromFile()
    {
        $this->logger->debug('Reading metadata from ' . $this->metadata_file);
        $fields = Yaml::parse($this->metadata_file);
        if (!is_array($fields)) {
            $fields = array();
            $this->logger->warning('No definition found in metadata file.');
        }
        $res = array();
        foreach ($fields as $field_data) {
            $res[$field_data['id']] = $field_data;
        }
        ksort($res);
        return $res;
    }

    /**
     * Compute the difference between two metadata arrays.
     * @param $base Base or old array.
     * @param $new New array with new definitions.
     * @param $add If true find new fields. Default: true
     * @param $del If true find fields to delete. Default: true
     * @param $update if true find modified fields; Default: true
     * @param $field_ids Array for field name to filter the results.
     * @return array Return a 3-row array for add, del and update fields.
     */
    public function diff($base, $new, $add = true, $del = true, $update = true, array $field_ids = array())
    {
        if (!empty($field_ids)) {
            $field_ids = array_flip($field_ids);
            $base = array_intersect_key($base, $field_ids);
            $new = array_intersect_key($new, $field_ids);
        }
        $res = array(
            self::ADD => array(),
            self::DEL => array(),
            self::UPDATE => array()
        );
        if ($add) {
            $res[self::ADD] = array_diff_key($new, $base);
        }
        if ($del) {
            $res[self::DEL] = array_diff_key($base, $new);
        }
        if ($update) {
            // Update array will have common fields with different data.
            $common = array_intersect_key($new, $base);
            foreach ($common as $field_name => $new_field_data) {
                $new_data = array_diff_assoc($new_field_data, $base[$field_name]);
                if (!empty($new_data)) {
                    $res[self::UPDATE][$field_name][self::BASE] = $base[$field_name];
                    $res[self::UPDATE][$field_name][self::MODIFIED] = $new_data;
                }
            }
        }
        return $res;
    }


    /**
     * Build Query for add field
     */
    public function getAddQuery(array $field_data)
    {
        $query = $this->getExternalDb()
            ->createQueryBuilder()
            ->insert(self::TABLE_NAME);
        //$idx = 0;
        foreach ($field_data as $key => $value) {
            $query->setValue($key, $query->createNamedParameter($value));
        }
        return $query;
    }

    /**
     * Build query for fields to delete
     */
    public function getDeleteQuery(array $field_data)
    {
        $query = $this->getExternalDb()
            ->createQueryBuilder()
            ->delete(self::TABLE_NAME);
        $query->where('id = ' . $query->createNamedParameter($field_data['id']));
        return $query;
    }

    /**
     *  Build query to update fields.
     */
    public function getUpdateQuery(array $field_data)
    {
        $query = $this->getExternalDb()
            ->createQueryBuilder()
            ->update(self::TABLE_NAME);
        $query->where('id = ' . $query->createNamedParameter($field_data[self::BASE]['id']));
        foreach ($field_data[self::MODIFIED] as $key => $value) {
            $query->set($key, $query->createNamedParameter($value));
        }
        return $query;
    }

    /**
     * Get the queries for a diff result
     */
    public function getQueries(array $diff_res)
    {
        $queries = array();
        foreach ($diff_res[self::ADD] as $new_field) {
            $queries[] = $this->getAddQuery($new_field);
        }
        foreach ($diff_res[self::DEL] as $del_field) {
            $queries[] = $this->getDeleteQuery($del_field);
        }
        foreach ($diff_res[self::UPDATE] as $mod_field) {
            $queries[] = $this->getUpdateQuery($mod_field);
        }
        return $queries;
    }

    /**
     * Get the sql query string for a query.
     */
    public function getSqlQuery($query)
    {
        $prepated_stmt = $query->getSql();
        $params = array();
        foreach ($query->getParameters() as $key => $value) {
            $params[":$key"] = "'$value'";
        }
        return str_replace(array_keys($params), $params, $prepated_stmt);
    }

    /**
     * Get the all the sql queries for a diff result.
     */
    public function getSqlQueries(array $diff_res)
    {
        $queries = $this->getQueries($diff_res);
        $sql = '';
        foreach ($queries as $query) {
            $sql .= $this->getSqlQuery($query) . ";\n";
        }
        return $sql;
    }

    /**
     * Execute DB queries for a diff result
     */
    public function executeQueries(array $diff_res)
    {
        $this->logger->debug('Exec sql queries.');
        $queries = $this->getQueries($diff_res);
        foreach ($queries as $query) {
            $query->execute();
        }
    }

    /**
     * Merge base metadata array with modifications from diff result
     */
    public function getMergedMetadata(array $base, array $diff_res)
    {
        $res = $base + $diff_res[self::ADD];
        $res = array_diff_key($res, $diff_res[self::DEL]);
        foreach ($diff_res[self::UPDATE] as $field_id => $values) {
            $new_values = array_merge($values[self::BASE], $values[self::MODIFIED]);
            $res[$field_id] = $new_values;
        }
        return $res;
    }

    /**
     * Write to file
     */
    public function writeFile(array $diff_res)
    {
        $base = $this->getFromFile();
        $merged_data = $this->getMergedMetadata($base, $diff_res);
        ksort($merged_data);
        $yaml = Yaml::dump(array_values($merged_data));
        file_put_contents($this->metadata_file, $yaml);
    }
}
