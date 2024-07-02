<?php
class Protocol {
    public function RESP2Decode(string $data): array {
        $fistByte = $data[0];
        if ($fistByte == "*") {
            // *<number-of-elements>\r\n$<length-of-element-1>\r\n<element-1>...<element-n>
            $data = substr($data, 1);
            // \r\n explode
            $arrayData = explode("\r\n", $data);
            $numberOfElements = $arrayData[0];
            if ($numberOfElements == 0) {
                return [];
            }
            // remove number-of-elements
            $arrayData = array_slice($arrayData, 1);
            // chunk
            $arrayData = array_chunk($arrayData, 2);
            $result = [];
            foreach ($arrayData as $group) {
                if (count($group) < 2) continue;
                $length = substr($group[0], 1);
                $element = $group[1];
                if (strlen($element) != $length) {
                    echo "Protocol length check error.\n";
                    throw new Exception("Protocol length check error.");
                }
                $result[] = $element;
            }
            return $result;
        }
        return [];
    }

    /**
     * @param array|string $input
     * @param int $type 0=bulk strings 1=simple strings 2=arrays
     * @return string
     */
    public function RESP2Encode(array|string $input, int $type = 0): string {
        return match ($type) {
            1 => $this->returnSimpleStrings($input),
            2 => $this->returnArrayTypeStrings($input),
            default => $this->defaultReturnBulkStrings($input),
        };
    }

    /**
     * https://redis.io/docs/latest/develop/reference/protocol-spec/#arrays
     * @param array $input
     * @return string
     */
    public function returnArrayTypeStrings(array $input): string
    {
        // *<number-of-elements>\r\n$<length-of-element-1>\r\n<element-1>...<element-n>
        $output = "*" . count($input) . "\r\n";
        foreach ($input as $element) {
            $output .= $this->defaultReturnBulkStrings($element);
        }
        return $output;
    }

    /**
     * return simple strings
     * https://redis.io/docs/latest/develop/reference/protocol-spec/#simple-strings
     * @param string $input
     * @return string
     */
    public function returnSimpleStrings(string $input): string
    {
        return "+" . $input . "\r\n";
    }

    /**
     * return bulk strings
     * https://redis.io/docs/latest/develop/reference/protocol-spec/#bulk-strings
     * @param string $input
     * @return string
     */
    public function defaultReturnBulkStrings(string $input): string
    {
        // null bulk string
        if (empty($input)) {
            return "$-1\r\n";
        }
        // default return bulk strings
        // $<length>\r\n<data>\r\n
        $output = "$";
        $output .= strlen($input);
        $output .= "\r\n";
        $output .= $input;
        $output .= "\r\n";
        return $output;
    }
}