<?php

namespace Eduardokum\LaravelBoleto\Api\Banco;

use Exception;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Eduardokum\LaravelBoleto\Util;
use Eduardokum\LaravelBoleto\Api\AbstractAPI;
use Eduardokum\LaravelBoleto\Api\Exception\CurlException;
use Eduardokum\LaravelBoleto\Api\Exception\HttpException;
use Eduardokum\LaravelBoleto\Exception\ValidationException;
use Eduardokum\LaravelBoleto\Boleto\Banco\Inter as BoletoInter;
use Eduardokum\LaravelBoleto\Api\Exception\UnauthorizedException;
use Eduardokum\LaravelBoleto\Contracts\Boleto\BoletoAPI as BoletoAPIContract;

class Inter extends AbstractAPI
{
    protected $baseUrl = 'https://cdpj.partners.bancointer.com.br';

    private $version = 3;

    /**
     * Campos necessários para o boleto
     *
     * @var array
     */
    protected $camposObrigatorios = [
        'conta',
        'certificado',
        'certificadoChave',
        'client_id',
        'client_secret',
    ];

    public function __construct($params = [])
    {
        $params['version'] = Arr::get($params, 'version', $this->version);
        if (in_array($params['version'], [1, 2])) {
            throw new ValidationException('Versão 1 e 2 da API foi descontinuada');
        }
        $this->setTokenStore(
            AbstractAPI::fileTokenStore(
                storage_path(sprintf('app/api_inter_token_%s.json', Util::onlyAlphanumber(Arr::get($params, 'conta'))))
            )
        );
        parent::__construct($params);
    }

    protected function oAuth2()
    {
        if ($this->getAccessToken()) {
            return $this;
        }
        $grant = $this->post($this->url('auth'), [
            'client_id'     => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'scope'         => 'boleto-cobranca.read boleto-cobranca.write',
            'grant_type'    => 'client_credentials',
        ], true)->body;

        return $this->setAccessToken('Bearer ' . $grant->access_token, $grant->expires_in);
    }

    /**
     * @return array
     */
    protected function headers()
    {
        return array_filter([
            'Authorization'          => $this->getAccessToken(),
            'x-inter-conta-corrente' => $this->getConta(),
        ]);
    }

    /**
     * @param $url
     * @param $type
     * @return bool
     * @throws ValidationException
     */
    public function createWebhook($url, $type = 'all')
    {
        try {
            $this->oAuth2()->put($this->url('webhook'), ['webhookUrl' => $url]);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param BoletoInter $boleto
     *
     * @return BoletoAPIContract
     * @throws CurlException
     * @throws HttpException
     * @throws UnauthorizedException
     * @throws ValidationException
     */
    public function createBoleto(BoletoAPIContract $boleto)
    {
        $data = $boleto->toAPI();
        $retorno = $this->oAuth2()->post($this->url('create'), $data);
        $boleto->setID($retorno->body->codigoSolicitacao);

        do {
            $show = $this->retrieveID($boleto->getID());
            if ($show->cobranca->situacao != 'A_RECEBER') {
                usleep(500000); // ~0.5s
                continue;
            }

            $boleto->setNossoNumero($show->boleto->nossoNumero);
            if (isset($show->pix)) {
                $boleto->setPixQrCode($show->pix->pixCopiaECola);
            }
        } while ($show->cobranca->situacao != 'A_RECEBER');

        return $boleto;
    }

    /**
     * @param array $inputedParams
     *
     * @return array
     * @throws CurlException
     * @throws HttpException
     * @throws UnauthorizedException
     */
    public function retrieveList($inputedParams = [])
    {
        $params = array_filter([
            'situacao'       => Arr::get($inputedParams, 'situacao', 'A_RECEBER,RECEBIDO,CANCELADO,EXPIRADO,EM_PROCESSAMENTO,ATRASADO'),
            'filtrarDataPor' => Arr::get($inputedParams, 'filtrarDataPor', 'VENCIMENTO'),
            'dataInicial'    => Arr::get($inputedParams, 'dataInicial', Carbon::now()->startOfMonth()->format('Y-m-d')),
            'dataFinal'      => Arr::get($inputedParams, 'dataFinal', Carbon::now()->endOfMonth()->format('Y-m-d')),
            'ordenarPor'     => Arr::get($inputedParams, 'ordenarPor', 'CODIGO_COBRANCA'),
            'paginacao'      => [
                'paginaAtual'    => 0,
                'itensPorPagina' => 1000,
            ],
        ], function ($v) {
            return ! is_null($v);
        });

        $aRetorno = [];
        do {
            $retorno = $this->oAuth2()->get($this->url('search') . http_build_query($params));
            array_push($aRetorno, ...$retorno->body->cobrancas);
            $params['paginacao']['paginaAtual'] += 1;
        } while (! $retorno->body->ultimaPagina);

        return array_map([$this, 'arrayToBoleto'], $aRetorno);
    }

    /**
     * @param $nossoNumero
     *
     * @return mixed
     * @throws CurlException
     * @throws HttpException
     * @throws UnauthorizedException
     * @throws ValidationException
     */
    public function retrieveNossoNumero($nossoNumero)
    {
        throw new ValidationException('Versão 3 da API somente recupera boleto pelo ID da cobrança');
    }

    /**
     * @param $id
     *
     * @return mixed
     * @throws CurlException
     * @throws HttpException
     * @throws UnauthorizedException
     * @throws ValidationException
     */
    public function retrieveID($id)
    {
        return $this->oAuth2()->get($this->url('show', $id))->body;
    }

    /**
     * @param        $nossoNumero
     * @param string $motivo
     *
     * @return mixed
     * @throws CurlException
     * @throws HttpException
     * @throws UnauthorizedException
     * @throws ValidationException
     */
    public function cancelNossoNumero($nossoNumero, $motivo = 'ACERTOS')
    {
        throw new ValidationException('Versão 3 da API somente cancela boleto pelo ID da cobrança');
    }

    /**
     * @param        $id
     * @param string $motivo
     *
     * @return mixed
     * @throws CurlException
     * @throws HttpException
     * @throws UnauthorizedException
     * @throws ValidationException
     */
    public function cancelID($id, $motivo = 'ACERTOS')
    {
        return $this->oAuth2()->post($this->url('cancel', $id), ['motivoCancelamento' => $motivo])->body;
    }

    /**
     * @param $nossoNumero
     *
     * @return mixed
     * @throws CurlException
     * @throws HttpException
     * @throws UnauthorizedException
     * @throws ValidationException
     */
    public function getPdfNossoNumero($nossoNumero)
    {
        throw new ValidationException('Versão 3 da API somente recupera PDF pelo ID da cobrança');
    }

    /**
     * @param $id
     *
     * @return mixed
     * @throws CurlException
     * @throws HttpException
     * @throws UnauthorizedException
     * @throws ValidationException
     */
    public function getPdfID($id)
    {
        return $this->oAuth2()->get($this->url('pdf', $id))->body;
    }

    /**
     * @param $boleto
     *
     * @return BoletoInter
     * @throws ValidationException
     */
    private function arrayToBoleto($boleto)
    {
        return BoletoInter::fromAPI($boleto, [
            'conta'        => $this->getConta(),
            'beneficiario' => $this->getBeneficiario(),
        ]);
    }

    /**
     * @param $type
     * @param $param
     * @return string
     */
    private function url($type, $param = null)
    {
        $aUrls = [
            3 => [
                'create'  => 'cobranca/v3/cobrancas',
                'show'    => 'cobranca/v3/cobrancas/' . $param,
                'cancel'  => 'cobranca/v3/cobrancas/' . $param . '/cancelar',
                'pdf'     => 'cobranca/v3/cobrancas/' . $param . '/pdf',
                'search'  => 'cobranca/v3/cobrancas?',
                'auth'    => '/oauth/v2/token',
                'webhook' => 'cobranca/v3/cobrancas/webhook',
            ],
        ];

        return Arr::get($aUrls, "$this->version.$type");
    }
}
