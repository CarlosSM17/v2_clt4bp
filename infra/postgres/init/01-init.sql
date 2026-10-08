CREATE DATABASE clt4bp_test OWNER clt4bp;

\connect clt4bp
CREATE EXTENSION IF NOT EXISTS vector;

\connect clt4bp_test
CREATE EXTENSION IF NOT EXISTS vector;
