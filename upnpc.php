<?php
/**
 * miniupnpc内upnpcコマンドのphpインタフェース。
 * UDP4500 Canceller用に必要な部分のみ実装。
 */

class upnpc
{
    public $isInstalled = false;
    /**
     * 「upnpc -l」実行結果
     */
    private $result = array();
    /**
     * WAN側IPアドレス
     */
    private $externalIPaddress;
    /**
     * LAN側IPアドレス(この機器のIPアドレス)
     */
    private $localIPaddress;
    /**
     * ポートマッピング一覧
     */
    private $mapping = array('TCP' => array(),
                             'UDP' => array());
    /**
     * サービスdescription
     */
    private $description = '';
    /**
     * サービスdescriptionを与えるためのupnpcコマンドラインオプション
     */
    private $option = '';

    public function __construct()
    {
        $this->getResult();
        $this->parseResult();
    }

    /**
     * upnpc -l 実行結果を取得
     */
    private function getResult()
    {
        $this->result = split("\n", chop(`upnpc -l`));
    }

    /**
     * 実行結果をパース
     */
    private function parseResult()
    {
        foreach ($this->result as $line) {
            //print "$line\n";

            if (preg_match('/^Local LAN ip address : ([\d\.]+)$/', $line, $vals)) {
                $this->localIPaddress = $vals[1];
            } else if (preg_match('/^ExternalIPAddress = ([\d\.]+)$/', $line, $vals)) {
                $this->externalIPaddress = $vals[1];
            } else if (preg_match('/(\d+) +([A-Za-z]+) +(\d+)->([\d\.]+)?:(\d+) +\'([^\']*)\' +\'([^\']*)\'( +(\d+))?/', $line, $vals)) {
                // i protocol exPort->inAddr:inPort 'description' 'remoteHost' leaseTime
                // Raspbian版だと末尾のleaseTime項目がない
                //print_r($vals);
                $no = $vals[1];
                $protocol = $vals[2];
                $exPort = $vals[3];
                $inAddr = $vals[4];
                $inPort = $vals[5];
                $description = $vals[6];
                $remoteHost = $vals[7];
                $leaseTime = (isset($vals[9]) ? $vals[9] : '');

                $this->mapping[$protocol][$exPort]
                    = array('inAddr' => $inAddr,
                            'inPort' => $inPort,
                            'description' => $description,
                            'remoteHost' => $remoteHost,
                            'leaseTime' => $leaseTime);
            }
        }

        // マッピング表をポート番号順にソート
        ksort($this->mapping['TCP']);
        ksort($this->mapping['UDP']);
    }
    
    /**
     * upnpc -l コマンド出力のダンプ
     */
    public function dumpResult()
    {
        print_r($this->result);
    }

    /**
     * 内部に持っている情報の整形表示
     */
    public function printInfo()
    {
        print "WAN: {$this->externalIPaddress}\n";
        print "LAN: {$this->localIPaddress}\n";

        // i protocol exPort->inAddr:inPort description remoteHost leaseTime
        print "Mappings:\n";
        $protocols = array('UDP', 'TCP');
        foreach ($protocols as $protocol) {
            if (count($this->mapping[$protocol]) > 0) {
                foreach ($this->mapping[$protocol] as $port => $map) {
                    print "{$protocol} {$port}->{$map['inAddr']}:{$map['inPort']} '{$map['description']}' '{$map['remoteHost']}' {$map['leaseTime']}\n";
                }
            } else {
                print "{$protocol} (No mapping)\n";
            }
        }

        print "Description: {$this->description}\n";
        print "Option: {$this->option}\n";
    }
    
    /**
     * WAN側ポートとTCP or UDPから、対応するLAN側マッピング情報(IPアドレスとポートを含む)を返す
     * @param int $port ポート番号
     * @param string $protocol WAN側プロトコル。"TCP" or "UDP"
     * @return mixed 対応するマッピング情報があればarray、なければfalseを返す
     */
    public function getMappingByWanPort($port, $protocol = 'TCP')
    {
        if ($this->isUsePort($port, $protocol)) {
            return $this->mapping[$protocol][$port];
        } else {
            return false;
        }
    }

    /**
     * WAN側ポートとTCP/UDPを指定し、ポートマッピングの設定有無を調べる
     * @param int $port WAN側ポート番号
     * @param string $protocol WAN側プロトコル。"TCP" or "UDP"
     * @return bool 設定あり＝true、設定なし＝false
     */
    public function isUsePort($port, $protocol = 'TCP')
    {
        return isset($this->mapping[$protocol][$port]);
    }

    /**
     * -eオプション使用可否チェック
     *
     * オプションなしでupnpcコマンドを実行した際に表示されるメッセージに
     * -e の説明があるかどうかで判断する
     *
     * @return bool -e使用可＝true、使用不可＝false
     */
    public function isEnableDescription()
    {
        $result = split("\n", `upnpc 2>&1`);
        //print_r(split("\n", $result));

        foreach ($result as $line) {
            if (preg_match('/-e description :/', $line)) {
                return true;
            }
        }
        return false;
    }

    /**
     * ポートマッピング設定削除
     * @param int $exPort WAN側ポート番号
     * @param string $protocol WAN側プロトコル。"TCP" or "UDP"
     */
    public function delete($exPort, $protocol = 'TCP')
    {
        $result = `upnpc -d $exPort $protocol`;
    }
    
    /**
     * ポートマッピング設定追加(自端末のlocalIPに向けた設定)
     *
     * service descriptionは別途事前にsetDescription()しておく。
     * 事前のsetDescription()がなされていない場合にはupnpcコマンドのデフォルトが
     * 適用される。
     * @param int $inPort LAN側ポート番号
     * @param string $protocol WAN側プロトコル。"TCP" or "UDP"
     */
    public function add($inPort, $protocol = 'TCP')
    {
        $result = `upnpc {$this->option} -r $inPort $protocol`;
    }

    /**
     * ポートマッピング追加時のservice description設定
     * @param string $desc サービスdescription
     * @param string $format コマンドラインオプションフォーマット
     */
    public function setDescription($desc = '', $format = '')
    {
        $this->description = $desc;
        $this->option = sprintf($format, $desc);
    }

    /**
     * サービスdescriptionが規定文字列に一致しているかチェック
     *
     * 事前にsetDescriptionしていた文字列と比較する。
     * @param string $desc 比較対象文字列
     * @return bool
     */
    public function isMatchDescription($desc)
    {
        //print "比較: [{$this->description}] [$desc]\n";
        return (strcmp($this->description, $desc) == 0 ? false : true);
    }
    
    private function checkInstalled()
    {
        $result = `which upnpc`;
        print_r(split("\n", $result));
        // not implemented yet
    }

}

?>
