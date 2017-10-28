<?php
namespace Fiber\Mysql;

class Result implements \Iterator, \JsonSerializable
{
    const FIELD_TYPE_DECIMAL     = 0x00;
    const FIELD_TYPE_TINY        = 0x01;
    const FIELD_TYPE_SHORT       = 0x02;
    const FIELD_TYPE_LONG        = 0x03;
    const FIELD_TYPE_FLOAT       = 0x04;
    const FIELD_TYPE_DOUBLE      = 0x05;
    const FIELD_TYPE_NULL        = 0x06;
    const FIELD_TYPE_TIMESTAMP   = 0x07;
    const FIELD_TYPE_LONGLONG    = 0x08;
    const FIELD_TYPE_INT24       = 0x09;
    const FIELD_TYPE_DATE        = 0x0a;
    const FIELD_TYPE_TIME        = 0x0b;
    const FIELD_TYPE_DATETIME    = 0x0c;
    const FIELD_TYPE_YEAR        = 0x0d;
    const FIELD_TYPE_NEWDATE     = 0x0e;
    const FIELD_TYPE_VARCHAR     = 0x0f;
    const FIELD_TYPE_BIT         = 0x10;
    const FIELD_TYPE_NEWDECIMAL  = 0xf6;
    const FIELD_TYPE_ENUM        = 0xf7;
    const FIELD_TYPE_SET         = 0xf8;
    const FIELD_TYPE_TINY_BLOB   = 0xf9;
    const FIELD_TYPE_MEDIUM_BLOB = 0xfa;
    const FIELD_TYPE_LONG_BLOB   = 0xfb;
    const FIELD_TYPE_BLOB        = 0xfc;
    const FIELD_TYPE_VAR_STRING  = 0xfd;
    const FIELD_TYPE_STRING      = 0xfe;
    const FIELD_TYPE_GEOMETRY    = 0xff;

    const FETCH_ASSOC = 1;
    const FETCH_CLASS = 2;

    private $columns;
    private $column_num;
    private $rows;

    private $position = 0;

    public function addColumn(array $column)
    {
        $this->columns[] = $column;
        $this->column_num++;
    }

    public function addRow(array $row)
    {
        $typed_row = [];
        for ($i = 0; $i < $this->column_num; $i++) {
            $info = $this->columns[$i];
            $value = $row[$i];
            switch ($info['type']) {
            case self::FIELD_TYPE_TINY:
            case self::FIELD_TYPE_SHORT:
            case self::FIELD_TYPE_LONG:
            case self::FIELD_TYPE_LONGLONG:
            case self::FIELD_TYPE_INT24:
                $value = (int) $value;
                break;
            case self::FIELD_TYPE_FLOAT:
            case self::FIELD_TYPE_DOUBLE:
                $value = (float) $value;
                break;
            }

            $typed_row[$info['name']] = $value;
        }

        $this->rows[] = $typed_row;
    }

    public function current()
    {
        return $this->rows[$this->position];
    }

    public function key()
    {
        return $this->position;
    }

    public function next()
    {
        $this->position++;
    }

    public function rewind ()
    {
        $this->position = 0;
    }

    public function valid()
    {
        return isset($this->rows[$this->position]);
    }

    public function jsonSerialize()
    {
        return $this->rows;
    }

    public function __toString()
    {
        return json_encode($this, JSON_UNESCAPED_UNICODE);
    }
}
