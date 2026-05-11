"""add mrt_reward to badge_templates

Revision ID: aa1e9db24736
Revises:
Create Date: 2026-05-10 15:36:39.545599
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa


revision: str = "aa1e9db24736"
down_revision: Union[str, None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column(
        "badge_templates",
        sa.Column("mrt_reward", sa.Float(), nullable=True),
    )


def downgrade() -> None:
    op.drop_column("badge_templates", "mrt_reward")