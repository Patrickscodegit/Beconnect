<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ZBateson\MailMimeParser\MailMimeParser;

class TestRoRoEmailParsing extends Command
{
    protected $signature = 'test:roro-parsing';
    protected $description = 'Test RO-RO email parsing';

    public function handle()
    {
        $this->info('=== Testing RO-RO Email Parsing ===');
        $this->newLine();

        $emailPath = base_path('RO-RO verscheping ANTWERPEN - MOMBASA, KENIA.eml');
        
        if (!file_exists($emailPath)) {
            $this->error('Email file not found: ' . $emailPath);
            return 1;
        }

        try {
            $mailParser = new MailMimeParser();
            $message = $mailParser->parse(fopen($emailPath, 'r'), true);
            
            $this->info('1. Basic Email Information:');
            $this->line('Subject: ' . $message->getHeaderValue('Subject'));
            $this->line('From: ' . $message->getHeaderValue('From'));
            $this->line('To: ' . $message->getHeaderValue('To'));
            $this->newLine();
            
            $this->info('2. Email Text Content:');
            $textContent = $message->getTextContent();
            if ($textContent) {
                $this->line('Plain text found:');
                $this->line('---');
                $this->line($textContent);
                $this->line('---');
                $this->newLine();
                
                // Look for dimensions in the text
                $this->info('3. Dimensions Analysis:');
                if (preg_match('/L(\d+)\s*cm/', $textContent, $matches)) {
                    $this->info('Length found: ' . $matches[1] . ' cm (' . ($matches[1]/100) . ' m)');
                }
                if (preg_match('/B(\d+)\s*cm/', $textContent, $matches)) {
                    $this->info('Width found: ' . $matches[1] . ' cm (' . ($matches[1]/100) . ' m)');
                }
                if (preg_match('/H(\d+)\s*cm/', $textContent, $matches)) {
                    $this->info('Height found: ' . $matches[1] . ' cm (' . ($matches[1]/100) . ' m)');
                }
                if (preg_match('/(\d+)KG/', $textContent, $matches)) {
                    $this->info('Weight found: ' . $matches[1] . ' kg');
                }
                
            } else {
                $this->error('No plain text content found');
            }

            $this->newLine();
            $this->info('4. HTML Content:');
            $htmlContent = $message->getHtmlContent();
            if ($htmlContent) {
                $this->line('HTML content length: ' . strlen($htmlContent) . ' chars');
                
                // Strip HTML and look for dimensions
                $strippedHtml = strip_tags($htmlContent);
                if (preg_match('/L(\d+)\s*cm/', $strippedHtml, $matches)) {
                    $this->info('Length in HTML: ' . $matches[1] . ' cm');
                }
                if (preg_match('/B(\d+)\s*cm/', $strippedHtml, $matches)) {
                    $this->info('Width in HTML: ' . $matches[1] . ' cm');
                }
                if (preg_match('/H(\d+)\s*cm/', $strippedHtml, $matches)) {
                    $this->info('Height in HTML: ' . $matches[1] . ' cm');
                }
            } else {
                $this->line('No HTML content found');
            }
            
        } catch (\Exception $e) {
            $this->error('Error parsing email: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getTraceAsString());
        }

        return 0;
    }
}
