<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Node;

/**
 * Albero effettivo del periodo (sponsor → sponsorizzati; non binario, non a matrice).
 *
 * Gli incaricati cessati sono trasparenti (Reg. art. 2.8): i loro sponsorizzati risalgono al
 * primo ascendente con contratto in essere. Il cessato resta come foglia per non perdere i
 * punti già generati, ma non può ricevere compensi né soddisfare requisiti.
 */
final class Tree
{
    /** @var array<string, Node> */
    public array $nodes = [];

    /** @var array<string, ?string> */
    public array $parent = [];

    /** @var array<string, list<string>> */
    public array $children = [];

    /** @var array<string, int> */
    public array $depth = [];

    /** @var list<string> */
    public array $bottomUp = [];

    /** @param list<Node> $nodes */
    public function __construct(array $nodes)
    {
        foreach ($nodes as $n) {
            if (isset($this->nodes[$n->id])) {
                throw new NetworkException("Incaricato duplicato {$n->id}");
            }
            $this->nodes[$n->id] = $n;
        }
        foreach ($this->nodes as $n) {
            $this->parent[$n->id] = $this->effectiveParent($n);
        }
        foreach ($this->parent as $id => $p) {
            $this->children[$p ?? ''][] = (string) $id;
        }
        foreach ($this->children as &$list) {
            sort($list, SORT_STRING);
        }
        unset($list);
        foreach (array_keys($this->nodes) as $id) {
            $this->computeDepth((string) $id);
        }
        $ids = array_map('strval', array_keys($this->nodes));
        // ordine: profondità decrescente, poi id crescente (stringa)
        usort($ids, fn (string $a, string $b) => $this->depth[$a] === $this->depth[$b]
            ? strcmp($a, $b)
            : $this->depth[$b] <=> $this->depth[$a]);
        $this->bottomUp = $ids;
    }

    private function effectiveParent(Node $n): ?string
    {
        $seen = [$n->id => true];
        $p = $n->sponsorId;
        while ($p !== null) {
            if (isset($seen[$p])) {
                throw new NetworkException("Ciclo nella rete da {$n->id}");
            }
            $seen[$p] = true;
            $parent = $this->nodes[$p] ?? null;
            if ($parent === null) {
                throw new NetworkException("Sponsor {$p} di {$n->id} assente dallo snapshot");
            }
            if ($parent->status !== 'terminated') {
                return $p;
            }
            $p = $parent->sponsorId;
        }

        return null;
    }

    private function computeDepth(string $id): int
    {
        if (isset($this->depth[$id])) {
            return $this->depth[$id];
        }
        $chain = [];
        $cur = $id;
        while ($cur !== null && ! isset($this->depth[$cur])) {
            $chain[] = $cur;
            $cur = $this->parent[$cur];
        }
        $base = $cur === null ? -1 : $this->depth[$cur];
        foreach (array_reverse($chain) as $c) {
            $this->depth[$c] = ++$base;
        }

        return $this->depth[$id];
    }

    /** @return list<string> dal più profondo al più alto */
    public function topDown(): array
    {
        return array_reverse($this->bottomUp);
    }

    public function isMember(string $id): bool
    {
        return $this->nodes[$id]->status !== 'terminated';
    }

    public function parentOf(string $id): ?string
    {
        return $this->parent[$id] ?? null;
    }

    /** @return \Generator<string> */
    public function ancestors(string $id): \Generator
    {
        $p = $this->parent[$id] ?? null;
        while ($p !== null) {
            yield $p;
            $p = $this->parent[$p] ?? null;
        }
    }

    /** Radici delle linee di un incaricato (i suoi figli effettivi). @return list<string> */
    public function lines(string $id): array
    {
        return $this->children[$id] ?? [];
    }

    /** @return list<string> */
    public function childrenOf(string $id): array
    {
        return $this->children[$id] ?? [];
    }

    /** @return list<string> */
    public function descendants(string $id): array
    {
        $out = [];
        $stack = $this->children[$id] ?? [];
        while ($stack !== []) {
            $c = array_pop($stack);
            $out[] = $c;
            foreach ($this->children[$c] ?? [] as $cc) {
                $stack[] = $cc;
            }
        }

        return $out;
    }
}
