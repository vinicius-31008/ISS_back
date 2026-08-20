<?php

class CalculadoraIss
{
    public function calcular(float $valorServico, float $aliquotaPercentual): array
    {
        if ($valorServico < 0) {
            throw new InvalidArgumentException('Valor do serviço não pode ser negativo.');
        }

        if ($aliquotaPercentual < 0 || $aliquotaPercentual > 100) {
            throw new InvalidArgumentException('Alíquota de ISS inválida.');
        }

        $baseCalculo = round($valorServico, 2);
        $valorIss = round($baseCalculo * ($aliquotaPercentual / 100), 2);

        return [
            'base_calculo' => $baseCalculo,
            'aliquota_iss' => $aliquotaPercentual,
            'valor_iss' => $valorIss,
        ];
    }
}
