<?php
    require 'vendor/autoload.php';

    interface Cache {
        public function get(string $key, $default = null);
        public function set(string $key, string $value = '');
        public function isset(string $key): bool;
        public function unset(string $key);
    }

    final class FileCache implements Cache {
        private string $filecache;
        private array $storage = [];
        public function __construct(string $dir, string $name = 'geo')
        {
            if (!file_exists($dir) || !is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $this->filecache = $dir . DIRECTORY_SEPARATOR . $name . '.json';
            if (file_exists($this->filecache) && is_file($this->filecache)) {
                try {
                    $this->storage = json_decode(
                        file_get_contents($this->filecache),
                        true
                    );
                } catch (\Exception $e) {

                }
            }
        }

        public function get($key, $default = null)
        {
            if (!$this->isset($key))
                return $default;
            $_key = $this->genKey($key);
            return $this->storage[$_key];
        }

        public function set(string $key, $value = null)
        {
            $_key = $this->genKey($key);
            $this->storage[$_key] = $value;
            $this->dump();
        }

        public function isset(string $key): bool
        {
            $_key = $this->genKey($key);
            return isset($this->storage[$_key]);
        }

        public function unset(string $key)
        {
            if (!$this->isset($key))
                return;
            $_key = $this->genKey($key);
            unset($this->storage[$_key]);
            $this->dump();
        }

        private function dump()
        {
            try {
                file_put_contents(
                    $this->filecache,
                    json_encode($this->storage)
                );
            } catch (\Exception $e) {

            }
        }

        private function genKey($key): string {
            return md5($key);
        }
    }

    final class Ip {
        private string $ip;
        private bool $is_ip4;

        public function __construct(string $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new ValueError('IP is not correct');
            }
            $this->ip = $ip;
            $this->is_ip4 = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4
            );
        }

        public function getIp(): string {
            return $this->ip;
        }

        public function isIpV4(): bool {
            return $this->is_ip4;
        }

        public function isIpV6(): bool {
            return !$this->is_ip4;
        }
    }

    final class Point
    {
        private float $lat;
        private float $lng;

        public function __construct(float $lat, float $lng)
        {
            $this->lat = $lat;
            $this->lng = $lng;
        }

        public function getLat(): float
        {
            return $this->lat;
        }

        public function getLng(): float
        {
            return $this->lng;
        }
    }

    final class Location {
        private Ip $ip;
        private string $country;
        private string $zip;
        private Point $point;
        private string $city;

        public function __construct(
            Ip $ip,
            string $country,
            string $city,
            string $zip,
            Point $point
        ) {
            $this->ip = $ip;
            $this->country = $country;
            $this->zip = $zip;
            $this->point = $point;
            $this->city = $city;
        }

        public function getIp(): Ip
        {
            return $this->ip;
        }

        public function getCountry(): string
        {
            return $this->country;
        }

        public function getZip(): string
        {
            return $this->zip;
        }

        public function getPoint(): Point
        {
            return $this->point;
        }

        public function getCity(): string
        {
            return $this->city;
        }
    }

    interface Locator {
        public function __construct(Requester $requester, array $options = []);
        public function locate(Ip $ip): Location | null;
    }

    abstract class LocatorService implements Locator {
        protected Requester $requester;

        public function __construct(Requester $requester, array $options = [])
        {
            $this->requester = $requester;
        }
    }

    interface Requester {
        public function request(string $url, bool $as_plain = false): array | string | null;
    }

    abstract class ARequester implements Requester {
        private Cache | null $cache = null;

        public function __construct(Cache | null $cache = null) {
            $this->cache = $cache;
        }

        protected final function fromCache($key) {
            return $this->cache ? $this->cache->get($key) : null;
        }

        protected final function toCache($key, $value) {
            if (!$this->cache)
                return;
            $this->cache->set($key, $value);
        }
    }

    final class FileGetRequester extends ARequester implements Requester {
        public function request(string $url, $as_plain = false): array|string|null
        {
            $cache = $this->fromCache($url);
            if ($cache)
                return $cache;
            try {
                $json = file_get_contents($url);
                if (!$as_plain) {
                    $json = json_decode($json, true);
                }
                if (!empty($json))
                    $this->toCache($url, $json);
                return $json;
            } catch (\Exception $e) {
                return null;
            }
        }
    }

    final class ChainLocator implements Locator {
        private Requester $requester;
        private array $services = [];
        public function __construct(Requester $requester, array $options = [])
        {
            $this->requester = $requester;
        }

        public function addService($className, array $options = []): void {
            try {
                $service = new $className($this->requester, $options);
                $this->services[] = $service;
            } catch (\Exception $e) {

            }
        }

        public function locate(Ip $ip): Location|null
        {
            foreach ($this->services as $service)
            {
                /**
                 * @var Locator $service
                 */
                echo get_class($service) . PHP_EOL;
                $data = $service->locate($ip);
                if (!$data || !$data->getCity() || $data->getCity() === '-')
                    continue;
                return $data;
            }
            return null;
        }
    }
    final class GuzzleRequester extends ARequester implements Requester {
        public function request(string $url, $as_plain = false): array|string|null
        {
            $cache = $this->fromCache($url);
            if ($cache)
                return $cache;
            $client = new \GuzzleHttp\Client([
                'http_errors' => false
            ]);
            try {
                $request = $client->get($url, [
                    'http_errors' => false
                ]);
            } catch (\Exception $e) {
                return null;
            }
            if ($request->getStatusCode() !== 200)
                return null;
            $cache = $request->getBody()->getContents();
            if (!$as_plain) {
                $cache = json_decode($cache, true);
            }
            if (!empty($cache))
                $this->toCache($url, $cache);
            return $cache;
        }
    }

    final class IpApiComService extends LocatorService implements Locator {

        public function locate(Ip $ip): Location|null
        {
            $url = 'http://ip-api.com/json/' . $ip->getIp();
            $json = $this->requester->request($url);
            if (!$json || !isset($json['status']) || $json['status'] != 'success')
                return null;
            $point = new Point(
                $json['lat'],
                $json['lon'],
            );
            return new Location(
                $ip,
                $json['country'],
                $json['city'],
                $json['zip'],
                $point
            );
        }
    }

    final class FreeIpApiService extends LocatorService implements Locator {
        public function locate(Ip $ip): Location|null
        {
            $url = 'https://freeipapi.com/api/json/' . $ip->getIp();
            $json = $this->requester->request($url);

            if (!$json)
                return null;
            $point = new Point(
                $json['latitude'],
                $json['longitude'],
            );
            return new Location(
                $ip,
                $json['countryName'],
                $json['cityName'],
                $json['zipCode'],
                $point
            );
        }
    }

    final class ReallyFreeGeoIpService extends LocatorService implements Locator {
        public function locate(Ip $ip): Location|null
        {
            $url = 'https://reallyfreegeoip.org/json/' . $ip->getIp();
            $json = $this->requester->request($url);
            if (!$json)
                return null;
            $point = new Point(
                $json['latitude'],
                $json['longitude'],
            );
            return new Location(
                $ip,
                $json['country_name'],
                $json['city'],
                $json['zip_code'],
                $point
            );
        }
    }

    final class IpGeolocationService extends LocatorService implements Locator {
        private string $token;
        public function __construct(Requester $requester, array $options = [])
        {
            if (empty($options['token']))
                throw new ValueError('Token not assign');
            $this->token = $options['token'];
            parent::__construct($requester);
        }

        public function locate(Ip $ip): Location|null
        {
            $url = 'https://api.ipgeolocation.io/ipgeo?apiKey=' . $this->token . '&ip=' . $ip->getIp();
            $json = $this->requester->request($url);
            if (!$json)
                return null;
            $point = new Point(
                $json['latitude'],
                $json['longitude'],
            );
            return new Location(
                $ip,
                $json['country_name'],
                $json['city'],
                $json['zipcode'],
                $point
            );
        }
    }

    final class ApiIpSbService extends LocatorService implements Locator {
        public function locate(Ip $ip): Location|null
        {
            $url = 'https://api.ip.sb/geoip/' . $ip->getIp();
            $json = $this->requester->request($url);
            if (!$json)
                return null;
            $point = new Point(
                $json['latitude'],
                $json['longitude'],
            );
            return new Location(
                $ip,
                $json['country'] ?? '',
                $json['city'] ?? '',
                $json['postal_code'] ?? '',
                $point
            );
        }
    }

    final class ApiHackerTargetService extends LocatorService implements Locator {
    public function locate(Ip $ip): Location|null
    {
        $url = 'https://api.hackertarget.com/ipgeo/?q=' . $ip->getIp();
        $text= $this->requester->request($url, true);
        $json = [];
        foreach (explode(PHP_EOL, $text) as $line) {
            $data = explode(': ', $line);
            $fname = trim($data[0]);
            $fvalue = trim($data[1]);
            if (preg_match('/country/i', $fname)) {
                $json['country'] = $fvalue;
            }
            if (preg_match('/city/i', $fname)) {
                $json['city'] = $fvalue;
            }
            if (preg_match('/latitude/i', $fname)) {
                $json['latitude'] = $fvalue;
            }
            if (preg_match('/latitude/i', $fname)) {
                $json['longitude'] = $fvalue;
            }
        }
        if (empty($json))
            return null;

        $point = new Point(
            $json['latitude'],
            $json['longitude'],
        );
        return new Location(
            $ip,
            $json['country'] ?? '',
            $json['city'] ?? '',
            '',
            $point
        );
    }
}

$ip = new Ip('77.79.133.234');
$service = new ChainLocator(
    new FileGetRequester(
        new FileCache(__DIR__ . '/cache', 'filegeo_local')
    ),
);

$service->addService(ApiHackerTargetService::class);
$service->addService(ApiIpSbService::class);
$service->addService(IpApiComService::class);
$service->addService(ReallyFreeGeoIpService::class);
$service->addService(FreeIpApiService::class);

$info = $service->locate($ip);
if ($info !== null) {
    print_r($info->getCountry());
    echo PHP_EOL;
    print_r($info->getCity());
    echo PHP_EOL;
    print_r($info->getZip());
    echo PHP_EOL;
    print_r($info->getPoint()->getLat());
    echo PHP_EOL;
    print_r($info->getPoint()->getLng());
    echo PHP_EOL;
    echo PHP_EOL;
} else {
    echo "Info about IP: {$ip->getIp()} not found";
}
