<?php

namespace App\Services;

use TCPDF;

class PDFService
{
    public function generatePurchaseRequestPDF($requestData)
    {
        $pdf = new TCPDF();
        $pdf->AddPage();

        // Set title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Purchase Request', 0, 1, 'C');

        // Add request details
        $pdf->SetFont('helvetica', '', 12);
        foreach ($requestData as $key => $value) {
            $pdf->Cell(0, 10, ucfirst($key) . ': ' . $value, 0, 1);
        }

        // Output PDF
        $pdf->Output('purchase_request.pdf', 'D');
    }

    public function generateInventoryReportPDF($inventoryData)
    {
        $pdf = new TCPDF();
        $pdf->AddPage();

        // Set title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Inventory Report', 0, 1, 'C');

        // Add inventory details
        $pdf->SetFont('helvetica', '', 12);
        foreach ($inventoryData as $item) {
            $pdf->Cell(0, 10, 'Item: ' . $item['name'] . ' - Status: ' . $item['status'], 0, 1);
        }

        // Output PDF
        $pdf->Output('inventory_report.pdf', 'D');
    }
}