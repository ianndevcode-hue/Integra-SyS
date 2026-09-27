Official NFS-e (Sistema Nacional) XSD schemas, layout v1.01.
Change vs. originals: regex anchors "^" and "$" were removed from <xs:pattern> values,
because XSD patterns are implicitly anchored and libxml (PHP) treats ^/$ as literals.
