<?php
// Legacy placeholder to avoid PSR-4 autoload conflicts.
// Use App\Services\PDFService in src/Services/PDFService.php.
namespace App\Services;

use Mpdf\Mpdf;

class PDFService
{
	public function generatePurchaseRequestPDF(array $requestData): void
	{
		$mpdf = new Mpdf();
		$html = '<h1 style="text-align:center">Purchase Request</h1>';
		$html .= '<table width="100%" border="1" cellspacing="0" cellpadding="6">';
		foreach ($requestData as $key => $value) {
			$k = htmlspecialchars((string)$key);
			$v = htmlspecialchars((string)$value);
			$html .= "<tr><td><strong>{$k}</strong></td><td>{$v}</td></tr>";
		}
		$html .= '</table>';
		$mpdf->WriteHTML($html);
		$mpdf->Output('purchase_request.pdf', 'D');
	}

	public function generateInventoryReportPDF(array $inventoryData): void
	{
		$mpdf = new Mpdf();
		$rows = '';
		foreach ($inventoryData as $item) {
			$name = htmlspecialchars((string)($item['name'] ?? ''));
			$status = htmlspecialchars((string)($item['status'] ?? ''));
			$rows .= "<tr><td>{$name}</td><td>{$status}</td></tr>";
		}
		$html = '<h1 style="text-align:center">Inventory Report</h1>' .
			'<table width="100%" border="1" cellspacing="0" cellpadding="6">' .
			'<thead><tr><th>Item</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table>';
		$mpdf->WriteHTML($html);
		$mpdf->Output('inventory_report.pdf', 'D');
	}

	public function generatePurchaseOrderPDF(array $poData): void
	{
		$mpdf = new Mpdf();
		$header = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">'
			. '<div style="font-size:22px;font-weight:700;">Purchase Order</div>'
			. '<div style="font-size:12px;color:#64748b;">Generated: ' . htmlspecialchars(date('Y-m-d H:i')) . '</div>'
			. '</div>';
		$rows = '';
		foreach ($poData as $k => $v) {
			$rows .= '<tr><td style="width:30%;font-weight:600;">' . htmlspecialchars((string)$k) . '</td><td>' . htmlspecialchars((string)$v) . '</td></tr>';
		}
		$html = $header . '<table width="100%" border="1" cellspacing="0" cellpadding="6">' . $rows . '</table>';
		$mpdf->WriteHTML($html);
		$filename = (string)($poData['PO Number'] ?? 'purchase_order') . '.pdf';
		$mpdf->Output($filename, 'D');
	}
}
